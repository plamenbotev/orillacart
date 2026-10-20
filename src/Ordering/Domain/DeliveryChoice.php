<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * The day, and time of day, the customer chose for delivery.
 */
final readonly class DeliveryChoice
{
    /**
     * @param \DateTimeImmutable $date the day, in the shop's calendar
     * @param string|null        $slot "09:00–12:00"; null for the whole day
     * @param string|null        $key  the carrier and slot it counts against: "5:morning"
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public ?string $slot = null,
        public ?string $key = null,
    ) {}
}
