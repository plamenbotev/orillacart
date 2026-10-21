<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Infrastructure\WordPress\Admin;

use OrillaCart\Reviews\Infrastructure\WordPress\CommentReviewStore;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The shop moderates reviews on WordPress's Comments screen: a type to list only them, and their stars and
 * buyer's mark beside each.
 */
#[AsHook('admin_comment_types_dropdown', method: 'types', filter: true)]
#[AsHook('manage_edit-comments_columns', method: 'columns', filter: true)]
#[AsHook('manage_comments_custom_column', method: 'column', arguments: 2)]
final readonly class ReviewsInComments
{
    private const string COLUMN = 'orillacart_rating';

    /**
     * @param array<string, string> $types
     *
     * @return array<string, string>
     */
    public function types(array $types): array
    {
        return $types + [CommentReviewStore::TYPE => __('Product reviews', 'orillacart')];
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        return $columns + [self::COLUMN => __('Rating', 'orillacart')];
    }

    public function column(string $column, int|string $commentId): void
    {
        if ($column !== self::COLUMN || get_comment_type((int) $commentId) !== CommentReviewStore::TYPE) {
            return;
        }
        $rating = get_comment_meta((int) $commentId, CommentReviewStore::RATING, true);
        $stars = is_numeric($rating) ? max(1, min(5, (int) $rating)) : 0;
        echo '<span aria-hidden="true">' . esc_html(str_repeat('★', $stars) . str_repeat('☆', 5 - $stars)) . '</span>';
        /* translators: %d: number of stars, 1 to 5 */
        echo '<span class="screen-reader-text">' . esc_html(\sprintf(_n('%d star', '%d stars', $stars, 'orillacart'), $stars)) . '</span>';
        if (get_comment_meta((int) $commentId, CommentReviewStore::VERIFIED, true) === '1') {
            echo '<br>' . esc_html__('Verified buyer', 'orillacart');
        }
    }
}
