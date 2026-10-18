<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * A page of matching products, how many match in all, and, when asked for, what each filter would leave.
 */
final readonly class SearchResult
{
    /**
     * @param list<int>   $ids      product ids, in the order asked for
     * @param list<Facet> $facets
     * @param array{string, string}|null $priceRange the lowest and highest price among the matches, ignoring the price filter
     */
    public function __construct(
        public array $ids,
        public int $total,
        public array $facets = [],
        public ?array $priceRange = null,
    ) {}

    public function facet(string $key): ?Facet
    {
        foreach ($this->facets as $facet) {
            if ($facet->key === $key) {
                return $facet;
            }
        }

        return null;
    }
}
