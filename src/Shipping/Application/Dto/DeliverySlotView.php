<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * A time of day the customer may choose on a delivery day; a full one takes no more orders that day.
 */
final readonly class DeliverySlotView
{
    public function __construct(
        public string $id,
        /** "09:00–12:00" */
        public string $label,
        public bool $full = false,
    ) {}
}
