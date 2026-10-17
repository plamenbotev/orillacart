<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * Finding products by what WordPress keeps of them: their name and categories.
 */
interface ProductSearch
{
    /**
     * Products by name, not in the trash: those whose name contains the text, or whose id is one of $alsoIds
     * (such as those whose SKU matched), in the category or its subcategories.
     *
     * @param list<int> $alsoIds
     *
     * @return array{list<int>, int} a page of product ids, and how many match in all
     */
    public function find(?string $text, ?int $categoryId, array $alsoIds, int $offset, int $limit): array;
}
