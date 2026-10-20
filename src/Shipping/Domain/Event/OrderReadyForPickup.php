<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * An order to collect from one of the shop's places is ready there: the customer can come for it.
 */
final readonly class OrderReadyForPickup implements DomainEvent
{
    public function __construct(public int $orderId) {}
}
