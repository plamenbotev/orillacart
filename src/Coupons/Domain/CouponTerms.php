<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * What a coupon takes off, on what, and when it holds.
 */
final readonly class CouponTerms
{
    /**
     * @param Money|null   $cap                 for a percentage: the most it takes off
     * @param list<string> $countries           shipping countries it holds for; none: all
     * @param list<int>    $productIds          it applies to these products, categories or brands; none of them: everything
     * @param list<int>    $categoryIds
     * @param list<int>    $brandIds
     * @param list<int>    $excludedProductIds  never to these, whatever else matches
     * @param list<int>    $excludedCategoryIds
     * @param int          $buyQuantity         buy-get: how many matching items to buy for each time it gives
     * @param int          $getQuantity         buy-get: how many items it takes the percentage off each time; a
     *                                          free gift: how many of the gift
     * @param list<int>    $getProductIds       buy-get: the items it gives off, by product or category; none of
     *                                          them: the matching items, the cheapest first
     * @param list<int>    $getCategoryIds
     * @param int|null     $giftProductId       a free gift: the product given, without variations or options
     */
    public function __construct(
        public string $code,
        public CouponKind $kind,
        public Money $amount,
        public string $description = '',
        public ?Money $cap = null,
        public bool $active = true,
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $endsAt = null,
        public ?Money $minSubtotal = null,
        public ?Money $maxSubtotal = null,
        public int $minQuantity = 0,
        public array $countries = [],
        public array $productIds = [],
        public array $categoryIds = [],
        public array $brandIds = [],
        public array $excludedProductIds = [],
        public array $excludedCategoryIds = [],
        public bool $excludeSaleItems = false,
        public bool $combines = false,
        public ?int $usageLimit = null,
        public ?int $perCustomerLimit = null,
        public bool $firstOrderOnly = false,
        public int $buyQuantity = 0,
        public int $getQuantity = 0,
        public array $getProductIds = [],
        public array $getCategoryIds = [],
        public ?int $giftProductId = null,
    ) {}
}
