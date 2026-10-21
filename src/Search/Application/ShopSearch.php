<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Domain\SearchFilter;
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
     * The filters the shop offers among the products of a category, or of none: those for every category and
     * those chosen for it or a category above it. They come in the order of the nearest of these categories that
     * orders its filters, those it leaves out after them; otherwise in the shop's order.
     *
     * @param list<int> $above the categories above it, nearest first
     *
     * @return list<SearchFilterView>
     */
    public function filters(?int $categoryId = null, array $above = []): array
    {
        $shown = array_values(array_filter($this->filters->all(), static fn(SearchFilter $filter): bool => $filter->showsIn($categoryId, ...$above)));
        foreach ($categoryId === null ? [] : [$categoryId, ...$above] as $ordering) {
            $places = array_map(static fn(SearchFilter $filter): ?int => $filter->positionIn($ordering), $shown);
            if (array_filter($places, static fn(?int $place): bool => $place !== null) === []) {
                continue;
            }
            // Stable: those the category leaves out keep the shop's order, after the others.
            $order = array_keys($shown);
            usort($order, static fn(int $a, int $b): int => [$places[$a] === null, $places[$a] ?? 0, $a] <=> [$places[$b] === null, $places[$b] ?? 0, $b]);
            $shown = array_map(static fn(int $i): SearchFilter => $shown[$i], $order);
            break;
        }

        return array_map(SearchFilterView::fromFilter(...), $shown);
    }

    public function search(SearchQuery $query): SearchResult
    {
        return $this->engine->search($query);
    }
}
