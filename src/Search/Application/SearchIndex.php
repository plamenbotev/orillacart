<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\SearchDocument;

/**
 * Where an engine keeps the documents it searches.
 */
interface SearchIndex
{
    /** Adds the product's document, or replaces the one it had. */
    public function put(SearchDocument $document): void;

    /**
     * @param list<int> $productIds products no longer listed
     */
    public function remove(array $productIds): void;
}
