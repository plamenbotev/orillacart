<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;

/**
 * The storefront's product search: published products by words, categories and the shop's filters.
 */
final readonly class ShopSearch
{
    public function __construct(private CatalogSearch $engine) {}

    public function search(SearchQuery $query): SearchResult
    {
        return $this->engine->search($query);
    }
}
