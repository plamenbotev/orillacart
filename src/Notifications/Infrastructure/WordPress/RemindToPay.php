<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\PaymentReminders;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every hour: reminds the customers whose orders wait for their payment.
 */
final readonly class RemindToPay implements ScheduledTask
{
    public function __construct(private PaymentReminders $reminders) {}

    public static function hook(): string
    {
        return 'orillacart_remind_to_pay';
    }

    public static function recurrence(): string
    {
        return 'hourly';
    }

    public function run(): void
    {
        $this->reminders->remind();
    }
}
