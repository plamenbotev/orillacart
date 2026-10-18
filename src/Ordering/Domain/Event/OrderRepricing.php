<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * The shop is about to change an order's items, and so its total, inside the editing transaction. An area that
 * holds the old total refuses with a RuleViolation, such as a payment open at its provider for it.
 */
final readonly class OrderRepricing implements DomainEvent
{
    public function __construct(public int $orderId) {}
}
