<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * A filter over attribute options: products offering one of the values, under one of the attribute names.
 */
final readonly class OptionFilter
{
    /**
     * @param string       $key        names the filter in the result's facets
     * @param list<string> $attributes attribute names it covers, such as "Size" and "Размер"
     * @param list<string> $values     the chosen option names; none chooses nothing, and the facet only counts
     */
    public function __construct(
        public string $key,
        public array $attributes,
        public array $values = [],
    ) {}
}
