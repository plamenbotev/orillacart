<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * A courier booked an order's shipment: the customer can follow it from now.
 */
final readonly class ConsignmentBooked implements DomainEvent
{
    public function __construct(
        public int $orderId,
        public int $consignmentId,
    ) {}
}
