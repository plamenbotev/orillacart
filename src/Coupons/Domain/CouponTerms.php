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
    ) {}
}
