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
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Domain\TranslationRuleViolation;

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
        private ContentTranslations $translations,
        private Transactions $transactions,
    ) {}

    /**
     * @return list<SearchFilterView> in the shop's order
     */
    public function filters(): array
    {
        $filters = $this->filters->all();
        $translations = $this->translations->of(SearchFilterTranslations::TYPE, array_map(static fn(SearchFilter $filter): int => $filter->id(), $filters));

        return array_map(static fn(SearchFilter $filter): SearchFilterView => SearchFilterView::fromFilter($filter, $translations[$filter->id()]['label'] ?? []), $filters);
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
     * @throws TranslationRuleViolation
     */
    public function create(SaveSearchFilter $command): SearchFilterView
    {
        $this->requireFreeKey($command->key, null);
        $filter = new SearchFilter($command->label, $command->key, $command->attributes, $command->position);

        return $this->save($filter, $command);
    }

    /**
     * @throws SearchFilterNotFound
     * @throws SearchRuleViolation
     * @throws TranslationRuleViolation
     */
    public function update(int $id, SaveSearchFilter $command): SearchFilterView
    {
        $filter = $this->get($id);
        $this->requireFreeKey($command->key, $id);
        $filter->change($command->label, $command->key, $command->attributes, $command->position);

        return $this->save($filter, $command);
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
        $filter = $this->get($id);
        $this->transactions->run(function () use ($filter): void {
            $this->translations->forget(SearchFilterTranslations::TYPE, [$filter->id()]);
            $this->filters->remove($filter);
        });
    }

    /**
     * The filter and its label's translations together, or neither; a new filter has its id once saved.
     *
     * @throws TranslationRuleViolation
     */
    private function save(SearchFilter $filter, SaveSearchFilter $command): SearchFilterView
    {
        $texts = $command->translations === null ? [] : $this->translations->check(SearchFilterTranslations::TYPE, ['label' => $command->translations]);
        $this->transactions->run(function () use ($filter, $texts): void {
            $this->filters->save($filter);
            $this->translations->write(SearchFilterTranslations::TYPE, $filter->id(), $texts);
        });

        return SearchFilterView::fromFilter($filter, $this->translations->of(SearchFilterTranslations::TYPE, [$filter->id()])[$filter->id()]['label'] ?? []);
    }

    private function requireFreeKey(string $key, ?int $exceptId): void
    {
        if ($this->filters->isKeyTaken($key, $exceptId)) {
            throw new SearchRuleViolation('Another filter uses the key "%s".', 'orillacart_duplicate_filter_key', values: [$key]);
        }
    }

    private function get(int $id): SearchFilter
    {
        return $this->filters->find($id) ?? throw new SearchFilterNotFound($id);
    }
}
