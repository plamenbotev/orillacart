<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * A variation an add-on adds or changes (API 26): its option of each attribute it differs in, by name; what it
 * leaves null follows the product (price) or stays as it is.
 */
final readonly class VariationDraft
{
    /**
     * @param array<string, string> $options attribute name → option name: { "Colour": "Red", "Size": "M" }; only when adding
     * @param string|null           $salePrice "" ends the sale
     * @param int|null              $stock     the level in the shop's first stockroom
     */
    public function __construct(
        public array $options = [],
        public ?string $price = null,
        public ?string $sku = null,
        public ?string $salePrice = null,
        public ?int $stock = null,
    ) {}
}
