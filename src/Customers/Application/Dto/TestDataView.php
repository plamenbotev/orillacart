<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

/** How many test orders and test customers there are, or were removed (design 62). */
final readonly class TestDataView
{
    public function __construct(
        public int $orders,
        public int $customers,
    ) {}
}
