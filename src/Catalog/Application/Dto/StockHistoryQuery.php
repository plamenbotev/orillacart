<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Inventory\Domain\StockReason;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Which stock movements to look at: a product's (its variations' too), a stockroom's, an order's, of a reason,
 * in a time; all of them, newest first, without any.
 */
final readonly class StockHistoryQuery
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        #[Assert\Positive]
        public ?int $product = null,
        #[Assert\Positive]
        public ?int $stockroom = null,
        #[Assert\Choice(callback: [StockReason::class, 'values'])]
        public ?string $reason = null,
        #[Assert\Positive]
        public ?int $order = null,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $from = null,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $before = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: self::MAX_PER_PAGE)]
        public int $perPage = 50,
    ) {}
}
