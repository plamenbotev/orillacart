<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

use OrillaCart\Search\Domain\SearchFilter;

final readonly class SearchFilterView
{
    /**
     * @param list<string> $attributes
     * @param list<int>    $categoryIds the categories it shows in, with those below them; none for every one
     */
    public function __construct(
        public int $id,
        public string $label,
        public string $key,
        public array $attributes,
        public int $position,
        public array $categoryIds = [],
    ) {}

    public static function fromFilter(SearchFilter $filter): self
    {
        return new self($filter->id(), $filter->label(), $filter->key(), $filter->attributes(), $filter->position(), $filter->categoryIds());
    }
}
