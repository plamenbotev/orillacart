<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;

/**
 * Finds published products in the search index. An engine reads only the documents it was given.
 */
interface CatalogSearch
{
    public function search(SearchQuery $query): SearchResult;
}
