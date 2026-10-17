<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * A customer placed an order. Announced as the action orillacart_order_placed with the order number.
 */
final readonly class OrderPlaced implements PublicEvent
{
    public function __construct(public int $orderId) {}

    public function hookName(): string
    {
        return 'order_placed';
    }

    public function hookArguments(): array
    {
        return [$this->orderId];
    }
}
