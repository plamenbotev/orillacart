<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * How many products a filter value would leave.
 */
final readonly class FacetCount
{
    /**
     * @param string $value as the query names it: a term id, or an option name as the index keeps it
     */
    public function __construct(
        public string $value,
        public string $label,
        public int $count,
    ) {}
}
