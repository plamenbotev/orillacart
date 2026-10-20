<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\CartReminders;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every hour: counts the carts left at checkout abandoned, and reminds the customers who asked.
 */
final readonly class RemindOfCarts implements ScheduledTask
{
    public function __construct(private CartReminders $reminders) {}

    public static function hook(): string
    {
        return 'orillacart_remind_of_carts';
    }

    public static function recurrence(): string
    {
        return 'hourly';
    }

    public function run(): void
    {
        $this->reminders->run();
    }
}
