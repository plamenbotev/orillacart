<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: a coupon as the shop sets it up. Amounts are decimals; dates ISO 8601; empty means no rule.
 */
final readonly class SaveCoupon
{
    public const string AMOUNT = '/^\d{1,11}(\.\d{1,4})?$/';
    public const string AMOUNT_MESSAGE = 'Use an amount with up to four decimals, such as 19.90.';

    /**
     * @param list<string> $countries
     * @param list<int>    $productIds
     * @param list<int>    $categoryIds
     * @param list<int>    $brandIds
     * @param list<int>    $excludedProductIds
     * @param list<int>    $excludedCategoryIds
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Enter a code.')]
        #[Assert\Length(max: Coupon::MAX_CODE_LENGTH)]
        public string $code,
        #[Assert\Choice(callback: [CouponKind::class, 'values'])]
        public string $kind,
        #[Assert\Regex(self::AMOUNT, message: self::AMOUNT_MESSAGE)]
        public string $amount = '0',
        #[Assert\Length(max: 255)]
        public string $description = '',
        #[Assert\Regex(self::AMOUNT, message: self::AMOUNT_MESSAGE)]
        public ?string $cap = null,
        public bool $active = true,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $startsAt = null,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $endsAt = null,
        #[Assert\Regex(self::AMOUNT, message: self::AMOUNT_MESSAGE)]
        public ?string $minSubtotal = null,
        #[Assert\Regex(self::AMOUNT, message: self::AMOUNT_MESSAGE)]
        public ?string $maxSubtotal = null,
        #[Assert\PositiveOrZero]
        public int $minQuantity = 0,
        #[Assert\All([new Assert\Regex('/^[A-Z]{2}$/')])]
        #[Assert\Count(max: 300)]
        public array $countries = [],
        #[Assert\Count(max: 1000)]
        public array $productIds = [],
        #[Assert\Count(max: 1000)]
        public array $categoryIds = [],
        #[Assert\Count(max: 1000)]
        public array $brandIds = [],
        #[Assert\Count(max: 1000)]
        public array $excludedProductIds = [],
        #[Assert\Count(max: 1000)]
        public array $excludedCategoryIds = [],
        public bool $excludeSaleItems = false,
        public bool $combines = false,
        #[Assert\Positive]
        public ?int $usageLimit = null,
        #[Assert\Positive]
        public ?int $perCustomerLimit = null,
        public bool $firstOrderOnly = false,
    ) {}
}
