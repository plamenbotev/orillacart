<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * A customer's WordPress account was deleted. What they bought stays; what only made sense with the account goes.
 */
final readonly class CustomerDeleted implements DomainEvent
{
    public function __construct(public int $customerId) {}
}
