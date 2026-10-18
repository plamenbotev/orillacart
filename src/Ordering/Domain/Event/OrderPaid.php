<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The money of an order came, however it came: the gateway, the shop marking it paid, or completing an order
 * paid on delivery. Announced as the action orillacart_order_paid with the order number.
 */
final readonly class OrderPaid implements PublicEvent
{
    public function __construct(public int $orderId) {}

    public function hookName(): string
    {
        return 'order_paid';
    }

    public function hookArguments(): array
    {
        return [$this->orderId];
    }
}
