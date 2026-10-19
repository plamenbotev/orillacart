<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * An order is about to ship somewhere else, inside the change's transaction. An area that sent it on its way
 * already, such as a shipment booked with the old address, refuses with a RuleViolation.
 */
final readonly class OrderReaddressing implements DomainEvent
{
    public function __construct(public int $orderId) {}
}
