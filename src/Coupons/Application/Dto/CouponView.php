<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

use OrillaCart\Coupons\Domain\Coupon;

final readonly class CouponView
{
    /**
     * @param list<string> $countries
     * @param list<int>    $productIds
     * @param list<int>    $categoryIds
     * @param list<int>    $brandIds
     * @param list<int>    $excludedProductIds
     * @param list<int>    $excludedCategoryIds
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $kind,
        public string $amount,
        public string $description,
        public ?string $cap,
        public bool $active,
        public ?string $startsAt,
        public ?string $endsAt,
        public ?string $minSubtotal,
        public ?string $maxSubtotal,
        public int $minQuantity,
        public array $countries,
        public array $productIds,
        public array $categoryIds,
        public array $brandIds,
        public array $excludedProductIds,
        public array $excludedCategoryIds,
        public bool $excludeSaleItems,
        public bool $combines,
        public ?int $usageLimit,
        public ?int $perCustomerLimit,
        public bool $firstOrderOnly,
        public int $uses,
        public bool $automatic = false,
    ) {}

    public static function fromCoupon(Coupon $coupon): self
    {
        $terms = $coupon->terms();

        return new self(
            $coupon->id(),
            $terms->code,
            $terms->kind->value,
            (string) $terms->amount,
            $terms->description,
            $terms->cap === null ? null : (string) $terms->cap,
            $terms->active,
            $terms->startsAt?->format(\DATE_ATOM),
            $terms->endsAt?->format(\DATE_ATOM),
            $terms->minSubtotal === null ? null : (string) $terms->minSubtotal,
            $terms->maxSubtotal === null ? null : (string) $terms->maxSubtotal,
            $terms->minQuantity,
            $terms->countries,
            $terms->productIds,
            $terms->categoryIds,
            $terms->brandIds,
            $terms->excludedProductIds,
            $terms->excludedCategoryIds,
            $terms->excludeSaleItems,
            $terms->combines,
            $terms->usageLimit,
            $terms->perCustomerLimit,
            $terms->firstOrderOnly,
            $coupon->uses(),
            $coupon->isAutomatic(),
        );
    }
}
