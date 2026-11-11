<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * A product as shoppers see it now, for add-ons reading the catalogue (API 22): today's price before tax, whether
 * it can be bought, and its variations.
 */
final readonly class ProductSummary
{
    /**
     * @param string                       $type         "simple", "variable", "virtual", "downloadable", "bundle", …
     * @param string                       $price        today's price before tax and options; for a product with variations, the lowest
     * @param string|null                  $regularPrice the price without the running sale; null when no sale runs
     * @param bool                         $forSale      published, purchasable, its price shown, and something of it available
     * @param int|null                     $available    how many can be sold; null when stock does not limit it
     * @param list<ProductVariationSummary> $variations
     * @param bool|null                    $published   whether shoppers see it, a draft or a trashed one not (API 26; null before)
     * @param list<AttributeSummary>       $attributes  every attribute it offers, its sets' and its own, with the options' ids (API 26)
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public ?string $sku,
        public string $price,
        public ?string $regularPrice,
        public bool $forSale,
        public ?int $available,
        public bool $onBackorder,
        public array $variations = [],
        public ?bool $published = null,
        public array $attributes = [],
    ) {}
}
