<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** One variation of a product, as shoppers see it now (API 22). */
final readonly class ProductVariationSummary
{
    /**
     * @param string $label the options that make it: "Red / M"
     */
    public function __construct(
        public int $id,
        public string $label,
        public ?string $sku,
        public string $price,
        public ?string $regularPrice,
        public bool $forSale,
        public ?int $available,
    ) {}
}
