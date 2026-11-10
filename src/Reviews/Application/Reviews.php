<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\OrderService;
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
 * from someone who paid for the product (by their account, or through the review link of their order) is marked as a
 * buyer's. The site's discussion settings decide which go out at once and which wait for the shop.
 */
final readonly class Reviews
{
    public const int PER_PAGE = 10;
    /** The highest page of reviews asked for that is read: past it there are none. */
    public const int MAX_PAGE = 10000;

    public function __construct(
        private ReviewStore $store,
        private ReviewSettingsRepository $settings,
        private OrderService $orders,
        private Accounts $accounts,
        private ProductTranslations $translations,
        private ReviewTokens $tokens,
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
     * @param string|null $token the token of the review link of their order (ReviewTokens), when they came from it
     */
    public function invitation(int $productId, ?int $customerId, #[\SensitiveParameter] ?string $token = null): ReviewInvitationView
    {
        $productId = $this->translations->original($productId);
        $policy = $this->settings->load()->policy;
        if ($policy === ReviewPolicy::Off) {
            return new ReviewInvitationView(false, ReviewInvitationView::OFF);
        }
        if (!$this->store->isOpen($productId)) {
            return new ReviewInvitationView(false, ReviewInvitationView::CLOSED);
        }
        try {
            $author = $this->author($productId, $customerId, $token);
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
        $productId = $this->translations->original($productId);
        $policy = $this->settings->load()->policy;
        if ($policy === ReviewPolicy::Off) {
            throw new ReviewRuleViolation('The shop takes no reviews.', 'orillacart_reviews_off');
        }
        if (!$this->store->isOpen($productId)) {
            throw new ReviewRuleViolation('This product takes no reviews.', 'orillacart_reviews_closed');
        }
        $author = $this->author($productId, $customerId, $command->token);
        if ($author === null) {
            if ($policy === ReviewPolicy::Buyers || $this->store->loginRequired()) {
                throw new ReviewRuleViolation('Log in to review the product.', 'orillacart_review_log_in');
            }
            $author = ['name' => trim($command->name ?? ''), 'email' => trim($command->email ?? ''), 'customer' => null, 'verified' => false];
            // Under an account's email, a guest's review would pass as the customer's, and keep them from writing
            // theirs; one email reviews a product once. Neither is taken, and the guest is answered as any guest
            // whose review waits for the shop: the answer must not tell whose email has an account, or reviewed.
            if ($this->store->isAccountEmail($author['email']) || $this->store->hasReviewed($productId, null, $author['email'])) {
                return new WrittenReviewView(ReviewStatus::Held->value, null);
            }
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
     * A page of the product's published reviews, newest first, with its stars: an empty one for a product that
     * is not published, or that asks the visitor for its password.
     *
     * @param bool $forAnyone for whoever asks, with no page of the product shown to them: a product with a
     *                        password gives none, whether or not this visitor has given it
     */
    public function page(int $productId, int $page = 1, bool $forAnyone = false): ReviewPageView
    {
        $productId = $this->translations->original($productId);
        if (!$this->store->isReadable($productId, $forAnyone)) {
            return new ReviewPageView(null, [], 1, 1);
        }
        $page = max(1, min(self::MAX_PAGE, $page));
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

        // A translation shows its original's stars (design 51).
        $originals = array_combine($productIds, $this->translations->originals($productIds));
        $ratings = array_map(RatingView::of(...), $this->store->ratings(array_values(array_unique($originals))));
        $byAsked = [];
        foreach ($originals as $id => $original) {
            if (isset($ratings[$original])) {
                $byAsked[$id] = $ratings[$original];
            }
        }

        return $byAsked;
    }

    /**
     * Who the visitor is, when the shop knows: from their order's review link, or their account. Their name goes
     * out as their first name and the first letter of their last.
     *
     * @return array{name: string, email: string, customer: ?int, verified: bool}|null null for a visitor the shop does not know
     *
     * @throws ReviewRuleViolation for a review link the shop never made, one for another product, or one that expired
     */
    private function author(int $productId, ?int $customerId, #[\SensitiveParameter] ?string $token): ?array
    {
        if ($token !== null) {
            $link = $this->tokens->read($token);
            $order = null;
            if ($link !== null && !$link['expired'] && $this->translations->original($link['product']) === $productId) {
                try {
                    $order = $this->orders->order($link['order']);
                } catch (OrderNotFound) {
                }
            }
            if ($order === null) {
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
            // Without names the store signs it in the site's language.
            return $last;
        }

        return $last === '' ? $first : $first . ' ' . mb_substr($last, 0, 1) . '.';
    }
}
