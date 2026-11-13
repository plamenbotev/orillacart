<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** A page of the products a CatalogQuery finds (API 29). */
final readonly class CatalogPage
{
    /**
     * @param list<CatalogProduct>          $products
     * @param int                           $total      how many match in all
     * @param list<CatalogFacet>            $facets     when asked for: categories, brands and the shop's filters
     * @param array{string, string}|null    $priceRange the lowest and highest price among the matches, the price filter aside
     * @param string|null                   $cards      the shop's cards of the products, as its own lists print them, when asked for
     * @param array<string, string>         $sorts      the sorts the shopper may choose, with their labels
     */
    public function __construct(
        public array $products,
        public int $total,
        public int $page,
        public int $pages,
        public int $perPage,
        public array $facets = [],
        public ?array $priceRange = null,
        public ?string $cards = null,
        public array $sorts = [],
    ) {}
}
