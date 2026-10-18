<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * A product, or one variation of it, inside a bundle, and how many of it.
 */
final readonly class BundleItem
{
    /**
     * @throws CatalogRuleViolation for fewer than one
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public int $quantity,
    ) {
        if ($quantity < 1 || $quantity > 9999) {
            throw new CatalogRuleViolation('A bundle holds between 1 and 9999 of each item.', 'orillacart_invalid_bundle');
        }
    }
}
