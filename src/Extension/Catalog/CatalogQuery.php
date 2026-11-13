<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** What a shopper looks for (API 29). Values within a filter are alternatives; the filters all hold. */
final readonly class CatalogQuery
{
    /** The sorts a listing takes; "relevance" only with words. */
    public const array SORTS = ['relevance', 'order', 'newest', 'name', 'price_asc', 'price_desc'];
    /** The most products one page holds. */
    public const int MOST_PER_PAGE = 24;

    /**
     * @param list<int>                   $brandIds
     * @param array<string, list<string>> $filters  the shop's filters by key (CatalogFacet::$key), the values chosen
     * @param string|null                 $priceMin a decimal amount, as prices are shown
     * @param string                      $sort     one of SORTS; best match with words, the shop's order without
     * @param bool                        $facets   count what each filter would leave (CatalogPage::$facets)
     * @param bool                        $cards    the shop's own cards of the page's products (CatalogPage::$cards)
     */
    public function __construct(
        public ?string $words = null,
        public ?int $categoryId = null,
        public ?int $tagId = null,
        public array $brandIds = [],
        public array $filters = [],
        public ?string $priceMin = null,
        public ?string $priceMax = null,
        public bool $inStock = false,
        public bool $featured = false,
        public bool $onSale = false,
        public string $sort = '',
        public int $page = 1,
        public int $perPage = 12,
        public bool $facets = false,
        public bool $cards = false,
    ) {}
}
