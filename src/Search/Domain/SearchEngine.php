<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

enum SearchEngine: string
{
    /** The shop's own database: MySQL FULLTEXT and its tables, with no setup. */
    case Database = 'database';
    /** An OpenSearch server: typo tolerance and stemming, for big catalogs. */
    case OpenSearch = 'opensearch';
}
