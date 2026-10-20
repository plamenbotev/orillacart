<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * The delivery day and time of day the customer chose, as the order keeps them.
 */
final readonly class DeliveryPick
{
    /**
     * @param string      $date "2026-10-21"
     * @param string|null $slot "09:00–12:00"; null for the whole day
     * @param string|null $key  the carrier and slot it counts against: "5:morning"
     */
    public function __construct(
        public string $date,
        public ?string $slot,
        public ?string $key,
    ) {}
}
