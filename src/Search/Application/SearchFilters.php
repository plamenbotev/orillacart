<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Search\Application\Dto\SaveSearchFilter;
use OrillaCart\Search\Application\Dto\SearchFilterView;
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
        $filter = new SearchFilter($command->label, $command->key, $command->attributes, $command->position, $command->categoryIds);
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
        $filter->change($command->label, $command->key, $command->attributes, $command->position, $command->categoryIds);
        $this->filters->save($filter);

        return SearchFilterView::fromFilter($filter);
    }

    /**
     * Each filter's place among a category's: by filter id, null for the shop's order there.
     *
     * @return array<int, int> those the category orders
     */
    public function orderIn(int $categoryId): array
    {
        $places = [];
        foreach ($this->filters->all() as $filter) {
            $place = $filter->positionIn($categoryId);
            if ($place !== null) {
                $places[$filter->id()] = $place;
            }
        }

        return $places;
    }

    /**
     * Orders a category's filters its own way; a filter given null, or not given, takes the shop's order there.
     *
     * @param array<int, ?int> $places by filter id
     *
     * @throws SearchRuleViolation
     */
    public function order(int $categoryId, array $places): void
    {
        foreach ($this->filters->all() as $filter) {
            $place = $places[$filter->id()] ?? null;
            if ($filter->positionIn($categoryId) !== $place) {
                $filter->placeIn($categoryId, $place);
                $this->filters->save($filter);
            }
        }
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
