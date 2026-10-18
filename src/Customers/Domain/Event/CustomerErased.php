<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * A customer was erased, as a privacy request asked; their account itself may stay. What holds their data
 * apart from orders, such as their cart, forgets it.
 */
final readonly class CustomerErased implements DomainEvent
{
    public function __construct(public int $customerId) {}
}
