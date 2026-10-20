<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * How many of one of the order's lines a shipment carries, or are left to send.
 */
final readonly class ShippedItemView
{
    public function __construct(
        public int $line,
        public string $name,
        public int $quantity,
    ) {}
}
