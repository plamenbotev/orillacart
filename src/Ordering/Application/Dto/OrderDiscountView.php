<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * A coupon an order used: its code, description and what it took off the items, or whether it made shipping free.
 */
final readonly class OrderDiscountView
{
    public function __construct(
        public string $code,
        public string $description,
        public string $amount,
        public bool $freeShipping = false,
    ) {}
}
