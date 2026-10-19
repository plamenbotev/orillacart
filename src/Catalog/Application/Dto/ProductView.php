<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\Pricing;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductDetails;
use OrillaCart\Catalog\Domain\ProductType;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Shared\Domain\Money;

/**
 * A product's commerce details as the product editor shows them.
 */
final readonly class ProductView
{
    /**
     * @param bool                $saved      false until the commerce details are first saved; the view then shows defaults
     * @param list<int>           $setIds
     * @param list<AttributeView> $attributes the product's own attributes
     * @param list<AttributeView> $offered    every attribute the product offers, from its active sets and its own
     * @param list<VariationView> $variations
     * @param list<DownloadView>  $downloads
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $saved,
        public string $type,
        public string $price,
        public ?SaleView $sale,
        public ?string $sku,
        public bool $taxable,
        public ?int $taxGroupId,
        public string $stockMode,
        public bool $purchasable,
        public bool $featured,
        public ?string $weight,
        public ?string $length,
        public ?string $width,
        public ?string $height,
        public array $setIds,
        public array $attributes,
        public array $offered,
        public array $variations,
        public ?int $downloadLimit = null,
        public ?int $downloadExpiryDays = null,
        public array $downloads = [],
        public string $priceVisibility = 'shop',
        public ?int $shippingClassId = null,
        public string $backorders = 'no',
        /** What it is made of when it is a bundle. */
        public ?BundleView $bundle = null,
        public ?int $backorderLimit = null,
    ) {}

    /**
     * @param (\Closure(int, ?int): string)|null $itemName names a bundle's products, and their variations by their options
     */
    public static function fromProduct(Product $product, string $name, ?\Closure $itemName = null): self
    {
        $bundle = $product->bundle();
        $details = $product->details();

        return new self(
            $product->id(),
            $name,
            true,
            $details->type->value,
            (string) $details->pricing->price(),
            SaleView::fromSale($details->pricing->sale()),
            $details->sku,
            $details->taxable,
            $details->taxGroupId,
            $details->stockMode->value,
            $details->purchasable,
            $details->featured,
            $details->weight,
            $details->length,
            $details->width,
            $details->height,
            array_map(static fn(AttributeSet $set): int => $set->id(), $product->sets()),
            array_map(AttributeView::fromAttribute(...), $product->ownAttributes()),
            array_map(AttributeView::fromAttribute(...), $product->attributes()),
            array_map(VariationView::fromVariation(...), $product->variations()),
            $details->downloadLimit,
            $details->downloadExpiryDays,
            array_map(DownloadView::fromFile(...), $product->downloads()),
            $details->priceVisibility->value,
            $details->shippingClassId,
            $details->backorders->value,
            $bundle === null ? null : BundleView::fromBundle($bundle, $itemName ?? static fn(int $productId, ?int $variationId): string => '#' . ($variationId ?? $productId)),
            $details->backorderLimit,
        );
    }

    /**
     * A product post whose commerce details were never saved.
     */
    public static function unsaved(int $id, string $name): self
    {
        $defaults = new ProductDetails(ProductType::Physical, new Pricing(Money::zero()));

        return new self($id, $name, false, $defaults->type->value, '0', null, null, $defaults->taxable, null, StockMode::Tracked->value, $defaults->purchasable, $defaults->featured, null, null, null, null, [], [], [], []);
    }
}
