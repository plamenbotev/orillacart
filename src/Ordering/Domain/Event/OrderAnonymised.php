<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * The customer was erased from an order, as a privacy request asked: what else holds their email forgets it.
 */
final readonly class OrderAnonymised implements DomainEvent
{
    public function __construct(
        public int $orderId,
        /** The email it had, for the others to find what they hold of it. */
        public string $email,
    ) {}
}
