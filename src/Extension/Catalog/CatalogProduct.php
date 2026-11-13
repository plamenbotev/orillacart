<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** A product as a shopper finds it in a listing (API 29). */
final readonly class CatalogProduct
{
    /**
     * @param string                                                         $url          its page
     * @param array{url: string, alt: string, width: int, height: int}|null $image        its picture, as the cards show it
     * @param string|null                                                    $price        today's price as the shop shows it to this shopper (with tax or without: $withTax); the lowest of its variations'; null when shoppers ask for it
     * @param string|null                                                    $regularPrice the price without the running sale; null when none runs
     * @param string|null                                                    $highestPrice the highest variation's, when their prices differ
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public string $url,
        public ?array $image,
        public ?string $price,
        public ?string $regularPrice,
        public ?string $highestPrice,
        public bool $withTax,
        public bool $forSale,
        public bool $inStock,
        public bool $onBackorder,
        public bool $featured,
    ) {}
}
