<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: a page of the stock sheet. "items" lists products and variations, "options" attribute options.
 */
final readonly class StockSheetQuery
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        #[Assert\Choice(choices: ['items', 'options'])]
        public string $kind = 'items',
        #[Assert\Positive]
        public ?int $stockroom = null,
        #[Assert\Positive]
        public ?int $category = null,
        #[Assert\Length(max: 100)]
        public ?string $search = null,
        #[Assert\Positive]
        public ?int $parent = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: self::MAX_PER_PAGE)]
        public int $perPage = 25,
    ) {}
}
