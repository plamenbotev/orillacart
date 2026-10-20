<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * What a variation sells for and how its stock works. Its options say which variation it is.
 */
final readonly class VariationDetails
{
    /**
     * @param list<int>   $optionIds one option of each attribute the variation differs in
     * @param string|null $weight    in the shop's weight unit; null for the product's, as for length, width and height
     * @param Backorders|null $backorders     null to follow the product
     * @param int|null        $backorderLimit null to follow the product
     * @param int|null        $lowStockThreshold null to follow the product
     */
    public function __construct(
        public array $optionIds,
        public Pricing $pricing,
        public ?string $sku = null,
        public StockMode $stockMode = StockMode::Tracked,
        public ?string $weight = null,
        public ?string $length = null,
        public ?string $width = null,
        public ?string $height = null,
        public ?Backorders $backorders = null,
        public ?int $backorderLimit = null,
        public ?int $lowStockThreshold = null,
    ) {
        ProductDetails::validSku($sku);
        ProductDetails::validBackorderLimit($backorderLimit);
        ProductDetails::validLowStockThreshold($lowStockThreshold);
        ProductDetails::validMeasures(['weight' => $weight, 'length' => $length, 'width' => $width, 'height' => $height]);
        if ($optionIds === []) {
            throw new CatalogRuleViolation('A variation needs at least one option.', 'orillacart_variation_without_options');
        }
    }
}
