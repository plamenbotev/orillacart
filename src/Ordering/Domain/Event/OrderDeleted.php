<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The shop deleted an order that was not paid, or was refunded. Announced as the action
 * orillacart_order_deleted with the order number.
 */
final readonly class OrderDeleted implements PublicEvent
{
    public function __construct(public int $orderId) {}

    public function hookName(): string
    {
        return 'order_deleted';
    }

    public function hookArguments(): array
    {
        return [$this->orderId];
    }
}
