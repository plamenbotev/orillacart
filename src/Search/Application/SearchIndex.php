<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\SearchDocument;

/**
 * Where an engine keeps the documents it searches.
 */
interface SearchIndex
{
    /**
     * Adds the products' documents, or replaces the ones they had, all together.
     *
     * @param list<SearchDocument> $documents
     */
    public function put(array $documents): void;

    /**
     * @param list<int> $productIds products no longer listed
     */
    public function remove(array $productIds): void;

    /**
     * The products it has documents of.
     *
     * @return list<int>
     */
    public function productIds(): array;
}
