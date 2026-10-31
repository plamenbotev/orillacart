<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

/**
 * A promotion as the promotion blocks tell of it: a coupon shown to shoppers, what it gives and on what, and for a
 * cart, whether it applies or what the cart still lacks for it. Amounts are in the shopper's currency.
 */
final readonly class PromotionView
{
    /**
     * @param string    $amount         a percentage, or an amount off in the currency; nothing for free shipping or a gift
     * @param list<int> $productIds     what it applies to; none of these, the categories or the brands: everything
     * @param list<int> $categoryIds
     * @param list<int> $brandIds
     * @param list<int> $getProductIds  buy-get: the items it gives off; none of them: the items it applies to
     * @param list<int> $getCategoryIds
     */
    public function __construct(
        public string $code,
        /** No code to enter: it applies by itself. */
        public bool $automatic,
        public string $kind,
        /** The shop's words for it, in the shopper's language; may be empty. */
        public string $description,
        public string $amount,
        public string $currency,
        /** The smallest order it takes; null for any. */
        public ?string $minSubtotal,
        public int $minQuantity,
        public int $buyQuantity,
        public int $getQuantity,
        public array $productIds,
        public array $categoryIds,
        public array $brandIds,
        public array $getProductIds,
        public array $getCategoryIds,
        public ?int $giftProductId,
        /** It applies to the cart now. */
        public bool $applied = false,
        /** Applied: what it takes off the items. */
        public ?string $saved = null,
        /** What the order's size counts now, and how much more it takes; null when large enough. */
        public ?string $spent = null,
        public ?string $short = null,
        /** The items in the cart it applies to, and how many it takes. */
        public int $units = 0,
        public int $neededUnits = 0,
        /** Buy-get naming the items it gives: one of those is still to be added. */
        public bool $needsGiven = false,
        /** How near the cart is, from 0 to 1. */
        public float $nearness = 1.0,
        /** Applied, and the shopper removed the items it gives: theirs to have back. */
        public bool $declined = false,
    ) {}

    /** How many more of the items it applies to the cart needs. */
    public function unitsShort(): int
    {
        return max(0, $this->neededUnits - $this->units);
    }
}
