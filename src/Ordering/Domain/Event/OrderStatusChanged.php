<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * An order moved to another status. Announced as the action orillacart_order_status_changed with the order
 * number, the new status and the previous one.
 */
final readonly class OrderStatusChanged implements PublicEvent
{
    public function __construct(
        public int $orderId,
        public OrderStatus $status,
        public OrderStatus $previous,
    ) {}

    public function hookName(): string
    {
        return 'order_status_changed';
    }

    public function hookArguments(): array
    {
        return [$this->orderId, $this->status->value, $this->previous->value];
    }
}
