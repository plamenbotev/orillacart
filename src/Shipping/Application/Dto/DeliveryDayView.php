<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * A day the customer may choose for delivery, with its times of day.
 */
final readonly class DeliveryDayView
{
    /**
     * @param string                $date  "2026-10-21"
     * @param list<DeliverySlotView> $slots none for a whole day
     */
    public function __construct(
        public string $date,
        public array $slots = [],
    ) {}
}
