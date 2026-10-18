<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The shop changed an order's items, its customer's details, or whose order it is. Status changes and refunds
 * have their own events. Announced as the action orillacart_order_changed with the order number.
 */
final readonly class OrderChanged implements PublicEvent
{
    public function __construct(public int $orderId) {}

    public function hookName(): string
    {
        return 'order_changed';
    }

    public function hookArguments(): array
    {
        return [$this->orderId];
    }
}
