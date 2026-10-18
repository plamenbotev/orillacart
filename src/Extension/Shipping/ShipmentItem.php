<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * Units of one product in a shipment, each measured as the product says; null where it says nothing.
 */
final readonly class ShipmentItem
{
    /**
     * @param string|null $weight of one unit, in the shipment's weight unit
     * @param string|null $length of one unit, in its dimension unit
     */
    public function __construct(
        public int $quantity,
        public ?string $weight = null,
        public ?string $length = null,
        public ?string $width = null,
        public ?string $height = null,
        public ?string $name = null,
    ) {}
}
