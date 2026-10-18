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
    /**
     * @param bool $tellCustomer whether the customer is emailed about it; not when the shop takes an order itself and chose not to
     * @param bool $tellShop     whether the shop is emailed about it; not about an order it took itself
     */
    public function __construct(
        public int $orderId,
        public OrderStatus $status,
        public OrderStatus $previous,
        public bool $tellCustomer = true,
        public bool $tellShop = true,
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
