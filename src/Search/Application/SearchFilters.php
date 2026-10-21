<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Search\Application\Dto\SaveSearchFilter;
use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Domain\CategoryFilters;
use OrillaCart\Search\Domain\CategoryFiltersRepository;
use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Search\Domain\SearchFilterNotFound;
use OrillaCart\Search\Domain\SearchFilterRepository;
use OrillaCart\Search\Domain\SearchRuleViolation;

/**
 * The filters the shop offers shoppers, as the settings screen keeps them. Values come from the index, so a
 * change shows at once, without building documents again.
 */
final readonly class SearchFilters
{
    public function __construct(
        private SearchFilterRepository $filters,
        private ProductLookup $products,
        private CategoryFiltersRepository $categories,
    ) {}

    /**
     * @return list<SearchFilterView> in the shop's order
     */
    public function filters(): array
    {
        return array_map(SearchFilterView::fromFilter(...), $this->filters->all());
    }

    /**
     * The attribute names products and attribute sets use, to choose from.
     *
     * @return list<string>
     */
    public function attributeNames(): array
    {
        return $this->products->attributeNames();
    }

    /**
     * @throws SearchRuleViolation
     */
    public function create(SaveSearchFilter $command): SearchFilterView
    {
        $this->requireFreeKey($command->key, null);
        $filter = new SearchFilter($command->label, $command->key, $command->attributes, $command->position);
        $this->filters->save($filter);

        return SearchFilterView::fromFilter($filter);
    }

    /**
     * @throws SearchFilterNotFound
     * @throws SearchRuleViolation
     */
    public function update(int $id, SaveSearchFilter $command): SearchFilterView
    {
        $filter = $this->get($id);
        $this->requireFreeKey($command->key, $id);
        $filter->change($command->label, $command->key, $command->attributes, $command->position);
        $this->filters->save($filter);

        return SearchFilterView::fromFilter($filter);
    }

    /**
     * The filters a category chose to offer, in their order; null when it offers what the one above it does.
     *
     * @return list<int>|null filter ids
     */
    public function chosenIn(int $categoryId): ?array
    {
        return ($this->categories->ofCategories([$categoryId])[$categoryId] ?? null)?->filterIds();
    }

    /**
     * The category offers only these filters, in this order; null for what the category above it offers.
     *
     * @param list<int>|null $filterIds
     *
     * @throws SearchRuleViolation
     */
    public function choose(int $categoryId, ?array $filterIds): void
    {
        if ($filterIds === null) {
            $this->categories->remove($categoryId);

            return;
        }
        $known = array_map(static fn(SearchFilter $filter): int => $filter->id(), $this->filters->all());
        $filterIds = array_values(array_filter($filterIds, static fn(int $id): bool => \in_array($id, $known, true)));
        $chosen = $this->categories->ofCategories([$categoryId])[$categoryId] ?? null;
        if ($chosen === null) {
            $chosen = new CategoryFilters($categoryId, $filterIds);
        } else {
            $chosen->choose($filterIds);
        }
        $this->categories->save($chosen);
    }

    /**
     * @throws SearchFilterNotFound
     */
    public function delete(int $id): void
    {
        $this->filters->remove($this->get($id));
    }

    private function requireFreeKey(string $key, ?int $exceptId): void
    {
        if ($this->filters->isKeyTaken($key, $exceptId)) {
            throw new SearchRuleViolation(\sprintf('Another filter uses the key "%s".', $key), 'orillacart_duplicate_filter_key');
        }
    }

    private function get(int $id): SearchFilter
    {
        return $this->filters->find($id) ?? throw new SearchFilterNotFound($id);
    }
}
