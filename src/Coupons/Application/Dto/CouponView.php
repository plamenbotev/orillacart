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
        public int $buyQuantity = 0,
        public int $getQuantity = 0,
        /** @var list<int> */
        public array $getProductIds = [],
        /** @var list<int> */
        public array $getCategoryIds = [],
        public ?int $giftProductId = null,
        public bool $overridesPriceRules = false,
        public bool $freeShippingEveryRate = false,
        /** Shown to shoppers on the promotion blocks. */
        public bool $advertised = false,
        /** @var array<string, array<string, string>> its description in the site's other languages, by field and locale */
        public array $translations = [],
    ) {}

    /**
     * @param array<string, array<string, string>> $translations
     */
    public static function fromCoupon(Coupon $coupon, array $translations = []): self
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
            $terms->buyQuantity,
            $terms->getQuantity,
            $terms->getProductIds,
            $terms->getCategoryIds,
            $terms->giftProductId,
            $terms->overridesPriceRules,
            $terms->freeShippingEveryRate,
            $coupon->isAdvertised(),
            $translations,
        );
    }
}
