<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * The shop is marking an order paid, inside the change's transaction. A payment the provider held for review
 * is the money the shop saw arrive: its area settles it and gives its transaction for the order to keep.
 */
final class OrderMarkingPaid implements DomainEvent
{
    public ?string $transactionId = null;

    public function __construct(public readonly int $orderId) {}
}
