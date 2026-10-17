<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * What a variation sells for and how its stock works. Its options say which variation it is.
 */
final readonly class VariationDetails
{
    /**
     * @param list<int> $optionIds one option of each attribute the variation differs in
     */
    public function __construct(
        public array $optionIds,
        public Pricing $pricing,
        public ?string $sku = null,
        public StockMode $stockMode = StockMode::Tracked,
    ) {
        ProductDetails::validSku($sku);
        if ($optionIds === []) {
            throw new CatalogRuleViolation('A variation needs at least one option.', 'orillacart_variation_without_options');
        }
    }
}
