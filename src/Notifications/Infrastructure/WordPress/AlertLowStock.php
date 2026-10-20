<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\LowStockAlerts;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every day: tells the shop what ran low in its stockrooms.
 */
final readonly class AlertLowStock implements ScheduledTask
{
    public function __construct(private LowStockAlerts $alerts) {}

    public static function hook(): string
    {
        return 'orillacart_alert_low_stock';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->alerts->alert();
    }
}
