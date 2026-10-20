<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * What a customer is worth to the shop: what they paid in the shop's currency less what went back, over how
 * many paid orders, and when they first and last ordered.
 */
final readonly class LifetimeValueView
{
    public function __construct(
        public string $currency,
        public string $spent,
        public int $paidOrders,
        /** What a paid order brought on average. */
        public string $average,
        public ?string $firstOrderAt,
        public ?string $lastOrderAt,
    ) {}
}
