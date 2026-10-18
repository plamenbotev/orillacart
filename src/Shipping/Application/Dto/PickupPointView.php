<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * An office or locker a carrier's service delivers to, for the customer to choose.
 */
final readonly class PickupPointView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $address,
        public string $hours = '',
    ) {}

    /** How the order and its emails name it: "Office Vitosha, 12 Vitosha Blvd, 1000 Sofia". */
    public function label(): string
    {
        return $this->name . ', ' . $this->address;
    }
}
