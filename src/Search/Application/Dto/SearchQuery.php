<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * What a shopper looks for. Values within a filter are alternatives, filters all hold; options, price and stock
 * hold for one and the same variant.
 */
final readonly class SearchQuery
{
    /**
     * @param list<int>          $categoryIds products in one of the categories or below them
     * @param list<int>          $brandIds
     * @param list<int>          $tagIds      products with one of the tags
     * @param list<OptionFilter> $options
     * @param string|null        $priceMin    today's price before tax, from
     * @param string|null        $priceMax    up to and including
     * @param int                $limit       how many products to list; 0 for the total and the counts only
     * @param bool               $facets      count what each filter would leave
     * @param string|null        $locale      the language shown, on a site in several: products are found by their words in it
     * @param bool               $untranslated also products without a translation into it, by their own words
     */
    public function __construct(
        public ?string $text = null,
        public array $categoryIds = [],
        public array $brandIds = [],
        public array $options = [],
        public ?string $priceMin = null,
        public ?string $priceMax = null,
        public bool $inStockOnly = false,
        public bool $featuredOnly = false,
        public bool $onSaleOnly = false,
        public SearchSort $sort = SearchSort::Order,
        public int $offset = 0,
        public int $limit = 12,
        public bool $facets = false,
        public array $tagIds = [],
        public ?string $locale = null,
        public bool $untranslated = true,
    ) {}

    public function inLanguage(?string $locale): self
    {
        return clone($this, ['locale' => $locale]);
    }
}
