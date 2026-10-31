<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\Backorders;
use OrillaCart\Catalog\Domain\PriceVisibility;
use OrillaCart\Catalog\Domain\ProductDetails;
use OrillaCart\Catalog\Domain\ProductType;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Shared\Domain\TaxCode;
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
        /** How many it may owe at most when it takes backorders; null for no limit. */
        #[Assert\Positive]
        public ?int $backorderLimit = null,
        /** What it is made of when it is a bundle; null for a product of its own. */
        #[Assert\Valid]
        public ?SaveBundle $bundle = null,
        /** The shop hears when a stockroom holds this many or fewer; null for the stockroom's or the shop's. */
        #[Assert\PositiveOrZero]
        public ?int $lowStockThreshold = null,
        /** What tax services call it, over its tax group's code; null for the group's. */
        #[Assert\Regex(TaxCode::PATTERN, message: TaxCode::MESSAGE)]
        public ?string $taxCode = null,
        /**
         * The product's updated_at as it was loaded, null for one never saved: a product saved since is refused (409
         * orillacart_product_changed) rather than overwritten. The assistant may leave it out to save over whatever
         * is there now.
         */
        public ?string $updatedAt = null,
    ) {}

    /**
     * The command that saves the product as it is, but for the changes, by argument name: what an import row
     * changes of it.
     *
     * @param array{price?: string, type?: string, sale?: ?SaveSale, sku?: ?string, taxable?: bool, stockMode?: string, purchasable?: bool, featured?: bool, weight?: ?string, length?: ?string, width?: ?string, height?: ?string, backorders?: string, backorderLimit?: ?int, lowStockThreshold?: ?int, taxCode?: ?string, attributes?: list<SaveAttribute>} $changes
     */
    public static function unchanged(ProductView $view, array $changes = []): self
    {
        $bundle = $view->bundle === null ? null : new SaveBundle(
            array_map(static fn(array $item): SaveBundleItem => new SaveBundleItem($item['product_id'], $item['quantity'], $item['variation_ids'], $item['optional']), $view->bundle->items),
            $view->bundle->pricing,
            $view->bundle->discount,
        );
        $arguments = [
            'price' => $view->price,
            'type' => $view->type,
            'sale' => $view->sale === null ? null : new SaveSale($view->sale->price, $view->sale->from, $view->sale->until),
            'sku' => $view->sku,
            'taxable' => $view->taxable,
            'taxGroupId' => $view->taxGroupId,
            'stockMode' => $view->stockMode,
            'purchasable' => $view->purchasable,
            'featured' => $view->featured,
            'weight' => $view->weight,
            'length' => $view->length,
            'width' => $view->width,
            'height' => $view->height,
            'setIds' => $view->setIds,
            'attributes' => array_map(static fn(AttributeView $attribute): SaveAttribute => new SaveAttribute(
                $attribute->name,
                array_map(static fn(OptionView $option): SaveOption => new SaveOption($option->name, $option->adjustment, $option->id, $option->adjustmentKind), $attribute->options),
                $attribute->required,
                $attribute->priceHidden,
                $attribute->id,
                inSpecifications: $attribute->inSpecifications,
            ), $view->attributes),
            'downloadLimit' => $view->downloadLimit,
            'downloadExpiryDays' => $view->downloadExpiryDays,
            'priceVisibility' => $view->priceVisibility,
            'shippingClassId' => $view->shippingClassId,
            'backorders' => $view->backorders,
            'backorderLimit' => $view->backorderLimit,
            'bundle' => $bundle,
            'lowStockThreshold' => $view->lowStockThreshold,
            'taxCode' => $view->taxCode,
        ];

        return new self(...array_merge($arguments, $changes));
    }

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
