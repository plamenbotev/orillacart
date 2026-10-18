<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * An order is about to be deleted, inside the deleting transaction. The areas holding rows of it let go of them,
 * or refuse with a RuleViolation when a row must stay, such as a payment still open at its provider.
 */
final readonly class OrderDeleting implements DomainEvent
{
    public function __construct(public int $orderId) {}
}
