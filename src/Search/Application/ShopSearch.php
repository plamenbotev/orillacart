<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Domain\SearchFilterRepository;

/**
 * The storefront's product search: published products by words, categories and the shop's filters.
 */
final readonly class ShopSearch
{
    public function __construct(
        private CatalogSearch $engine,
        private SearchFilterRepository $filters,
    ) {}

    /**
     * The filters the shop offers, in its order.
     *
     * @return list<SearchFilterView>
     */
    public function filters(): array
    {
        return array_map(SearchFilterView::fromFilter(...), $this->filters->all());
    }

    public function search(SearchQuery $query): SearchResult
    {
        return $this->engine->search($query);
    }
}
