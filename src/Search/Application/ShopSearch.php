<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Domain\CategoryFiltersRepository;
use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Search\Domain\SearchFilterRepository;
use OrillaCart\Shared\I18n\ContentLanguages;

/**
 * The storefront's product search: published products by words, categories and the shop's filters.
 */
final readonly class ShopSearch
{
    public function __construct(
        private CatalogSearch $engine,
        private SearchFilterRepository $filters,
        private CategoryFiltersRepository $categories,
        private ContentLanguages $languages,
    ) {}

    /**
     * The filters the shop offers among the products of a category, or of none: those the nearest of it and the
     * categories above it that chooses its own offers, in its order; otherwise every filter, in the shop's.
     *
     * @param list<int> $above the categories above it, nearest first
     *
     * @return list<SearchFilterView>
     */
    public function filters(?int $categoryId = null, array $above = []): array
    {
        $all = $this->filters->all();
        $path = $categoryId === null ? [] : [$categoryId, ...$above];
        $chosen = $this->categories->ofCategories($path);
        foreach ($path as $category) {
            if (isset($chosen[$category])) {
                $byId = [];
                foreach ($all as $filter) {
                    $byId[$filter->id()] = $filter;
                }
                // A filter since removed is left out.
                $all = array_values(array_filter(array_map(static fn(int $id): ?SearchFilter => $byId[$id] ?? null, $chosen[$category]->filterIds())));
                break;
            }
        }

        return array_map(SearchFilterView::fromFilter(...), $all);
    }

    /**
     * Looks in the query's language, else the one shown on a site in several (design 51): the request's, or the
     * one the shop works in.
     */
    public function search(SearchQuery $query): SearchResult
    {
        if ($query->locale === null && $this->languages->multilingual()) {
            $query = $query->inLanguage($this->languages->current()?->locale);
        }

        return $this->engine->search($query);
    }
}
