<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The shop deleted an order that was cancelled or failed, never paid, or a test order. Announced as the action
 * orillacart_order_deleted with the order number.
 */
final readonly class OrderDeleted implements PublicEvent
{
    /**
     * @param bool $test a test order removed with the shop's test data (design 62): nothing outside the shop is told
     */
    public function __construct(public int $orderId, public bool $test = false) {}

    public function hookName(): string
    {
        return 'order_deleted';
    }

    public function hookArguments(): array
    {
        return [$this->orderId];
    }
}
