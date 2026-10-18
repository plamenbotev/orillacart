<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: a page of orders, newest first. The search matches the order number, email or a name.
 */
final readonly class OrderQuery
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        #[Assert\Choice(callback: [OrderStatus::class, 'values'])]
        public ?string $status = null,
        #[Assert\Length(max: 100)]
        public ?string $search = null,
        #[Assert\Positive]
        public ?int $customerId = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: self::MAX_PER_PAGE)]
        public int $perPage = 20,
        /** Only orders the shop has to look at. */
        public bool $attention = false,
    ) {}
}
