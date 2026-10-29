<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** What a product picker asks for: products named with the words, or those it holds already, by id. */
final readonly class ProductChoiceQuery
{
    public function __construct(
        #[Assert\Length(max: 200)]
        public string $search = '',
        /** Comma-separated product ids: "12,40". */
        #[Assert\Regex('/^(\d+(,\d+)*)?$/', message: 'Give product ids separated by commas, such as 12,40.')]
        public string $include = '',
    ) {}

    /** @return list<int> */
    public function ids(): array
    {
        return $this->include === '' ? [] : array_values(array_unique(array_map(intval(...), explode(',', $this->include))));
    }
}
