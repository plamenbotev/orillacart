<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\Backorders;
use OrillaCart\Catalog\Domain\PriceVisibility;
use OrillaCart\Catalog\Domain\ProductDetails;
use OrillaCart\Catalog\Domain\ProductType;
use OrillaCart\Catalog\Domain\StockMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: save a product's commerce details, its own attributes and its attribute sets, all at once,
 * as the product editor sends them. Variations are saved on their own.
 */
final readonly class SaveProduct
{
    public const string AMOUNT = '/^\d{1,11}(\.\d{1,4})?$/';
    public const string AMOUNT_MESSAGE = 'Use an amount of zero or more with up to four decimals, such as 19.90.';
    public const string SIGNED_AMOUNT = '/^-?\d{1,11}(\.\d{1,4})?$/';
    public const string SIGNED_AMOUNT_MESSAGE = 'Use an amount with up to four decimals, such as 2.50 or -1.';
    public const string MEASURE = '/^\d{1,8}(\.\d{1,4})?$/';
    public const string MEASURE_MESSAGE = 'Use a number of zero or more with up to four decimals.';

    /**
     * @param list<int>           $setIds     attribute sets whose attributes the product offers
     * @param list<SaveAttribute> $attributes the product's own attributes, in display order
     */
    public function __construct(
        #[Assert\Regex(self::AMOUNT, message: self::AMOUNT_MESSAGE)]
        public string $price,
        #[Assert\Choice(callback: [self::class, 'types'])]
        public string $type = 'physical',
        #[Assert\Valid]
        public ?SaveSale $sale = null,
        #[Assert\Length(min: 1, max: ProductDetails::MAX_SKU_LENGTH)]
        public ?string $sku = null,
        public bool $taxable = true,
        #[Assert\Positive]
        public ?int $taxGroupId = null,
        #[Assert\Choice(callback: [self::class, 'stockModes'])]
        public string $stockMode = 'tracked',
        public bool $purchasable = true,
        public bool $featured = false,
        #[Assert\Regex(self::MEASURE, message: self::MEASURE_MESSAGE)]
        public ?string $weight = null,
        #[Assert\Regex(self::MEASURE, message: self::MEASURE_MESSAGE)]
        public ?string $length = null,
        #[Assert\Regex(self::MEASURE, message: self::MEASURE_MESSAGE)]
        public ?string $width = null,
        #[Assert\Regex(self::MEASURE, message: self::MEASURE_MESSAGE)]
        public ?string $height = null,
        #[Assert\All([new Assert\Positive()])]
        public array $setIds = [],
        #[Assert\Valid]
        public array $attributes = [],
        #[Assert\Positive]
        public ?int $downloadLimit = null,
        #[Assert\Positive]
        public ?int $downloadExpiryDays = null,
        #[Assert\Choice(callback: [PriceVisibility::class, 'values'])]
        public string $priceVisibility = 'shop',
        #[Assert\Positive]
        public ?int $shippingClassId = null,
        /** no, yes, or notify: sell on when counted stock runs out, telling the customer */
        #[Assert\Choice(callback: [Backorders::class, 'values'])]
        public string $backorders = 'no',
        /** What it is made of when it is a bundle; null for a product of its own. */
        #[Assert\Valid]
        public ?SaveBundle $bundle = null,
    ) {}

    /** @return list<string> */
    public static function types(): array
    {
        return array_map(static fn(ProductType $type): string => $type->value, ProductType::cases());
    }

    /** @return list<string> */
    public static function stockModes(): array
    {
        return array_map(static fn(StockMode $mode): string => $mode->value, StockMode::cases());
    }
}
