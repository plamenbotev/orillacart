<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\WordPress;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every hour: cancels orders whose customer left to pay at a provider and never paid, so their stock sells again.
 */
final readonly class CancelAbandonedOrders implements ScheduledTask
{
    public function __construct(private OrderService $orders) {}

    public static function hook(): string
    {
        return 'orillacart_cancel_abandoned_orders';
    }

    public static function recurrence(): string
    {
        return 'hourly';
    }

    public function run(): void
    {
        $this->orders->cancelAbandoned();
    }
}
