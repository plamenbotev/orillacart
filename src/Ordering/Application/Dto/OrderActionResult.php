<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * What an order action answered, and the order with its answer in the history.
 */
final readonly class OrderActionResult
{
    public function __construct(
        public string $message,
        public OrderView $order,
    ) {}
}
