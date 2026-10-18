<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * A shipping class is gone; the products in it now have none.
 */
final readonly class ShippingClassDeleted implements DomainEvent
{
    public function __construct(public int $id) {}
}
