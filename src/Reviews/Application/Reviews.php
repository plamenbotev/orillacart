<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinkExpired;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Reviews\Application\Dto\RatingView;
use OrillaCart\Reviews\Application\Dto\ReviewInvitationView;
use OrillaCart\Reviews\Application\Dto\ReviewPageView;
use OrillaCart\Reviews\Application\Dto\ReviewSettingsView;
use OrillaCart\Reviews\Application\Dto\ReviewView;
use OrillaCart\Reviews\Application\Dto\UpdateReviewSettings;
use OrillaCart\Reviews\Application\Dto\WriteReview;
use OrillaCart\Reviews\Application\Dto\WrittenReviewView;
use OrillaCart\Reviews\Domain\NewReview;
use OrillaCart\Reviews\Domain\ReviewPolicy;
use OrillaCart\Reviews\Domain\ReviewRuleViolation;
use OrillaCart\Reviews\Domain\ReviewSettings;
use OrillaCart\Reviews\Domain\ReviewSettingsRepository;
use OrillaCart\Reviews\Domain\ReviewStatus;
use OrillaCart\Reviews\Domain\ReviewStore;

/**
 * Product reviews: stars and words, from whoever the shop's policy lets write, each once per product. A review
 * from someone who paid for the product (by their account, or through the link to their receipt) is marked as a
 * buyer's. The site's discussion settings decide which go out at once and which wait for the shop.
 */
final readonly class Reviews
{
    public const int PER_PAGE = 10;

    public function __construct(
        private ReviewStore $store,
        private ReviewSettingsRepository $settings,
        private OrderService $orders,
        private Accounts $accounts,
    ) {}

    public function settings(): ReviewSettingsView
    {
        return new ReviewSettingsView($this->settings->load()->policy->value);
    }

    public function updateSettings(UpdateReviewSettings $command): ReviewSettingsView
    {
        $this->settings->save(new ReviewSettings(ReviewPolicy::from($command->policy)));

        return $this->settings();
    }

    /** Whether products show reviews at all. */
    public function shown(): bool
    {
        return $this->settings->load()->policy !== ReviewPolicy::Off;
    }

    /**
     * Whether the product page offers the visitor to write a review.
     *
     * @param string|null $receipt the token of the link to their receipt, when they came from it
     */
    public function invitation(int $productId, ?int $customerId, ?string $receipt = null): ReviewInvitationView
    {
        $policy = $this->settings->load()->policy;
        if ($policy === ReviewPolicy::Off) {
            return new ReviewInvitationView(false, ReviewInvitationView::OFF);
        }
        if (!$this->store->isOpen($productId)) {
            return new ReviewInvitationView(false, ReviewInvitationView::CLOSED);
        }
        try {
            $author = $this->author($productId, $customerId, $receipt);
        } catch (ReviewRuleViolation) {
            $author = $this->author($productId, $customerId, null);
        }
        if ($author === null) {
            return $policy === ReviewPolicy::Buyers || $this->store->loginRequired()
                ? new ReviewInvitationView(false, ReviewInvitationView::LOG_IN)
                : new ReviewInvitationView(true);
        }
        if ($this->store->hasReviewed($productId, $author['customer'], $author['email'])) {
            return new ReviewInvitationView(false, ReviewInvitationView::REVIEWED);
        }
        if ($policy === ReviewPolicy::Buyers && !$author['verified']) {
            return new ReviewInvitationView(false, ReviewInvitationView::BUYERS_ONLY);
        }

        return new ReviewInvitationView(true, null, $author['name'], $author['verified']);
    }

    /**
     * @throws ReviewRuleViolation when the shop takes no review from them, or the site refuses it
     */
    public function write(int $productId, WriteReview $command, ?int $customerId): WrittenReviewView
    {
        $policy = $this->settings->load()->policy;
        if ($policy === ReviewPolicy::Off) {
            throw new ReviewRuleViolation('The shop takes no reviews.', 'orillacart_reviews_off');
        }
        if (!$this->store->isOpen($productId)) {
            throw new ReviewRuleViolation('This product takes no reviews.', 'orillacart_reviews_closed');
        }
        $author = $this->author($productId, $customerId, $command->receipt);
        if ($author === null) {
            if ($policy === ReviewPolicy::Buyers || $this->store->loginRequired()) {
                throw new ReviewRuleViolation('Log in to review the product.', 'orillacart_review_log_in');
            }
            $author = ['name' => trim($command->name ?? ''), 'email' => trim($command->email ?? ''), 'customer' => null, 'verified' => false];
        }
        if ($this->store->hasReviewed($productId, $author['customer'], $author['email'])) {
            throw new ReviewRuleViolation('You reviewed this product already.', 'orillacart_already_reviewed');
        }
        if ($policy === ReviewPolicy::Buyers && !$author['verified']) {
            throw new ReviewRuleViolation('Only those who bought the product may review it.', 'orillacart_reviews_buyers_only');
        }
        $review = $this->store->add(new NewReview($productId, $command->rating, trim($command->text), $author['name'], $author['email'], $author['customer'], $author['verified']));

        // Spam is not the writer's to know: it waits, as far as they can tell.
        return new WrittenReviewView($review->status === ReviewStatus::Published ? ReviewStatus::Published->value : ReviewStatus::Held->value, ReviewView::of($review));
    }

    /**
     * A page of the product's published reviews, newest first, with its stars.
     */
    public function page(int $productId, int $page = 1): ReviewPageView
    {
        $page = max(1, $page);
        [$reviews, $total] = $this->store->published($productId, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $rating = $this->store->ratings([$productId])[$productId] ?? null;

        return new ReviewPageView(
            $rating === null ? null : RatingView::of($rating),
            array_map(ReviewView::of(...), $reviews),
            $page,
            max(1, (int) ceil($total / self::PER_PAGE)),
        );
    }

    /**
     * The products' stars, for lists of products; those without published reviews are left out.
     *
     * @param list<int> $productIds
     *
     * @return array<int, RatingView>
     */
    public function ratings(array $productIds): array
    {
        if ($productIds === [] || !$this->shown()) {
            return [];
        }

        return array_map(RatingView::of(...), $this->store->ratings($productIds));
    }

    /**
     * Who the visitor is, when the shop knows: from their receipt's link, or their account. Their name goes out
     * as their first name and the first letter of their last.
     *
     * @return array{name: string, email: string, customer: ?int, verified: bool}|null null for a visitor the shop does not know
     *
     * @throws ReviewRuleViolation for a receipt link the shop never made, or one that expired
     */
    private function author(int $productId, ?int $customerId, ?string $receipt): ?array
    {
        if ($receipt !== null) {
            try {
                $order = $this->orders->byReceipt($receipt);
            } catch (OrderNotFound|ReceiptLinkExpired) {
                throw new ReviewRuleViolation('The link to your order has expired: open the order from its latest email.', 'orillacart_review_receipt');
            }
            $bought = $order->paidAt !== null && !\in_array($order->status, ['cancelled', 'failed'], true)
                && array_filter($order->lines, static fn(OrderLineView $line): bool => $line->productId === $productId) !== [];

            return ['name' => self::shortName($order->billing->firstName, $order->billing->lastName), 'email' => $order->email, 'customer' => $order->customerId, 'verified' => $bought];
        }
        if ($customerId === null) {
            return null;
        }
        $account = $this->accounts->find($customerId);
        if ($account === null) {
            return null;
        }

        return [
            'name' => self::shortName($account->firstName, $account->lastName),
            'email' => $account->email,
            'customer' => $customerId,
            'verified' => $this->orders->boughtProducts($customerId, $account->email, [$productId]) !== [],
        ];
    }

    private static function shortName(string $first, string $last): string
    {
        $first = trim($first);
        $last = trim($last);
        if ($first === '') {
            return $last !== '' ? $last : 'Customer';
        }

        return $last === '' ? $first : $first . ' ' . mb_substr($last, 0, 1) . '.';
    }
}
