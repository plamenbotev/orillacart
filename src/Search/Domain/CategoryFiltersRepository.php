<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

interface CategoryFiltersRepository
{
    /**
     * @param list<int> $categoryIds
     *
     * @return array<int, CategoryFilters> those of the categories that choose their own, by category id
     */
    public function ofCategories(array $categoryIds): array;

    public function save(CategoryFilters $filters): void;

    /** The category offers what the one above it does again. */
    public function remove(int $categoryId): void;
}
