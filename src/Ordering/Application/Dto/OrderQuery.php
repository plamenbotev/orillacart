<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderSearch;
use OrillaCart\Ordering\Domain\OrderStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: a page of orders, newest first unless sorted otherwise. The search matches the order number, email or a
 * name; the other filters narrow by when the order was placed (moments, as the shop's days begin and end), the way
 * it was paid, its total, and whether a customer or a guest placed it.
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
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $placedFrom = null,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $placedBefore = null,
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $payment = null,
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/', message: 'Enter an amount such as 25.50.')]
        public ?string $minTotal = null,
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/', message: 'Enter an amount such as 25.50.')]
        public ?string $maxTotal = null,
        #[Assert\Choice(choices: [OrderSearch::ACCOUNTS, OrderSearch::GUESTS])]
        public ?string $placedBy = null,
        #[Assert\Choice(choices: [OrderSearch::BY_NUMBER, OrderSearch::BY_TOTAL])]
        public string $orderBy = OrderSearch::BY_NUMBER,
        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $order = 'desc',
    ) {}
}
