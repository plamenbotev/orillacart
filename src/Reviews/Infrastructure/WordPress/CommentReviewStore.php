<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Infrastructure\WordPress;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Reviews\Domain\NewReview;
use OrillaCart\Reviews\Domain\Rating;
use OrillaCart\Reviews\Domain\Review;
use OrillaCart\Reviews\Domain\ReviewReply;
use OrillaCart\Reviews\Domain\ReviewRuleViolation;
use OrillaCart\Reviews\Domain\ReviewStatus;
use OrillaCart\Reviews\Domain\ReviewStore;
use OrillaCart\Shared\Http\Security\ClientAddress;

/**
 * Reviews are comments of their own type on the product's post, with the stars and the buyer's mark as comment
 * meta. They go through wp_new_comment, so the site's moderation, its disallowed words, flood and duplicate
 * checks and spam plugins such as Akismet see them as any comment; replies to them, such as the shop's from the
 * Comments screen, are ordinary comments.
 */
final readonly class CommentReviewStore implements ReviewStore
{
    public const string TYPE = 'orillacart_review';
    public const string RATING = 'orillacart_rating';
    public const string VERIFIED = 'orillacart_verified';

    public function __construct(private EntityManagerInterface $entityManager) {}

    public function isOpen(int $productId): bool
    {
        return get_post_type($productId) === ProductPostType::NAME && get_post_status($productId) === 'publish' && comments_open($productId);
    }

    public function loginRequired(): bool
    {
        return (bool) get_option('comment_registration');
    }

    public function add(NewReview $review): Review
    {
        $address = ClientAddress::of($_SERVER);
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $id = wp_new_comment(wp_slash([
            'comment_post_ID' => $review->productId,
            'comment_author' => $review->authorName !== '' ? $review->authorName : __('Customer', 'orillacart'),
            'comment_author_email' => $review->authorEmail,
            'comment_author_url' => '',
            'comment_content' => $review->text,
            'comment_type' => self::TYPE,
            'comment_parent' => 0,
            'user_id' => $review->customerId ?? 0,
            'comment_author_IP' => (string) preg_replace('/[^0-9a-fA-F:., ]/', '', $address),
            'comment_agent' => \is_string($agent) ? mb_substr($agent, 0, 254) : '',
            'comment_meta' => [self::RATING => $review->rating, self::VERIFIED => $review->verified ? 1 : 0],
        ]), true);
        if ($id instanceof \WP_Error) {
            throw new ReviewRuleViolation(match ($id->get_error_code()) {
                'comment_duplicate' => 'You wrote this review already.',
                'comment_flood' => 'You are reviewing too quickly. Wait a moment and try again.',
                default => wp_strip_all_tags($id->get_error_message()),
            }, 'orillacart_review_refused');
        }
        $comment = \is_int($id) ? get_comment($id) : null;
        if (!$comment instanceof \WP_Comment) {
            throw new ReviewRuleViolation('The review could not be saved.', 'orillacart_review_refused');
        }

        return self::review($comment, []);
    }

    public function hasReviewed(int $productId, ?int $customerId, string $email): bool
    {
        // Spam too: 'all' is only published and held, and someone marked spam would review again and again.
        $query = ['post_id' => $productId, 'type' => self::TYPE, 'parent' => 0, 'status' => ['approve', 'hold', 'spam'], 'count' => true];
        if ($customerId !== null && (int) get_comments($query + ['user_id' => $customerId]) > 0) {
            return true;
        }

        return $email !== '' && (int) get_comments($query + ['author_email' => $email]) > 0;
    }

    public function published(int $productId, int $offset, int $limit): array
    {
        $query = new \WP_Comment_Query([
            'post_id' => $productId,
            'type' => self::TYPE,
            'parent' => 0,
            'status' => 'approve',
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
            'number' => max(1, $limit),
            'offset' => max(0, $offset),
            'no_found_rows' => false,
        ]);
        $comments = array_values(array_filter(\is_array($query->comments) ? $query->comments : [], static fn(mixed $comment): bool => $comment instanceof \WP_Comment));
        $replies = [];
        if ($comments !== []) {
            $answers = get_comments([
                'parent__in' => array_map(static fn(\WP_Comment $comment): int => (int) $comment->comment_ID, $comments),
                'status' => 'approve',
                'orderby' => 'comment_date_gmt',
                'order' => 'ASC',
            ]);
            foreach (\is_array($answers) ? $answers : [] as $answer) {
                if ($answer instanceof \WP_Comment) {
                    $replies[(int) $answer->comment_parent][] = new ReviewReply($answer->comment_author, $answer->comment_content, self::date($answer));
                }
            }
        }

        return [
            array_map(static fn(\WP_Comment $comment): Review => self::review($comment, $replies[(int) $comment->comment_ID] ?? []), $comments),
            $query->found_comments,
        ];
    }

    public function ratings(array $productIds): array
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $ids = array_values(array_unique(array_map(intval(...), $productIds)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT c.comment_post_ID AS product, m.meta_value AS stars, COUNT(*) AS reviews FROM {$wpdb->comments} c
             JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = :rating
             WHERE c.comment_post_ID IN (:products) AND c.comment_type = :type AND c.comment_approved = '1' AND c.comment_parent = 0
             GROUP BY c.comment_post_ID, m.meta_value",
            ['rating' => self::RATING, 'products' => $ids, 'type' => self::TYPE],
            ['products' => ArrayParameterType::INTEGER],
        );
        $stars = [];
        foreach ($rows as $row) {
            $number = is_numeric($row['stars'] ?? null) ? (int) $row['stars'] : 0;
            if ($number >= 1 && $number <= 5 && is_numeric($row['product'] ?? null) && is_numeric($row['reviews'] ?? null)) {
                $stars[(int) $row['product']][$number] = (int) $row['reviews'];
            }
        }

        return array_map(static fn(array $counts): Rating => new Rating($counts), $stars);
    }

    /**
     * @param list<ReviewReply> $replies
     */
    private static function review(\WP_Comment $comment, array $replies): Review
    {
        $rating = get_comment_meta((int) $comment->comment_ID, self::RATING, true);

        return new Review(
            (int) $comment->comment_ID,
            (int) $comment->comment_post_ID,
            max(1, min(5, is_numeric($rating) ? (int) $rating : 5)),
            $comment->comment_content,
            $comment->comment_author,
            get_comment_meta((int) $comment->comment_ID, self::VERIFIED, true) === '1',
            match ($comment->comment_approved) {
                '1' => ReviewStatus::Published,
                'spam' => ReviewStatus::Spam,
                default => ReviewStatus::Held,
            },
            self::date($comment),
            $replies,
        );
    }

    private static function date(\WP_Comment $comment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($comment->comment_date_gmt !== '0000-00-00 00:00:00' ? $comment->comment_date_gmt : 'now', new \DateTimeZone('UTC'));
    }
}
