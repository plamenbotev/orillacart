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
     * @param list<OptionFilter> $options
     * @param string|null        $priceMin    today's price before tax, from
     * @param string|null        $priceMax    up to and including
     * @param int                $limit       how many products to list; 0 for the total and the counts only
     * @param bool               $facets      count what each filter would leave
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
    ) {}
}
