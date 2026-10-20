<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * A product, or one of its variations, as a row of the products file. Add-ons find their own data by its ids.
 *
 * @param int|null $variationId null for the product itself
 * @param string   $name        with the variation's options: "T-shirt – Red / M"
 * @param string   $price       before tax, as the catalog has it
 */
final readonly class ProductItem
{
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public string $name,
        public ?string $sku,
        public string $price,
    ) {}
}
