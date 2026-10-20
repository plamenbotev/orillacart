<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Infrastructure\WordPress;

use OrillaCart\Checkout\Application\UnpaidOrders;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every hour: cancels the orders that waited too long for a bank transfer or cheque.
 */
final readonly class ExpireUnpaidOrders implements ScheduledTask
{
    public function __construct(private UnpaidOrders $orders) {}

    public static function hook(): string
    {
        return 'orillacart_expire_unpaid_orders';
    }

    public static function recurrence(): string
    {
        return 'hourly';
    }

    public function run(): void
    {
        $this->orders->expire();
    }
}
