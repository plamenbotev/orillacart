<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** The values of one filter with how many products each would show, its own choice aside (API 29). */
final readonly class CatalogFacet
{
    /**
     * @param string                                                             $key    "category", "brand", or a shop filter's key (CatalogQuery::$filters)
     * @param list<array{value: string, label: string, count: int, chosen: bool}> $values
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $values,
    ) {}
}
