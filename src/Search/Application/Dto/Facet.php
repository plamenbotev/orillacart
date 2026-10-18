<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * The values of one filter with their counts, which ignore what is chosen in this filter itself.
 */
final readonly class Facet
{
    public const string CATEGORIES = 'category';
    public const string BRANDS = 'brand';

    /**
     * @param string           $key    Facet::CATEGORIES, Facet::BRANDS or an option filter's key
     * @param list<FacetCount> $values by label
     */
    public function __construct(
        public string $key,
        public array $values,
    ) {}
}
