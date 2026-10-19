<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\WordPress;

use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Daily: forgets the providers' events handled more than a month ago.
 */
final readonly class ForgetNotifiedEvents implements ScheduledTask
{
    public function __construct(private PaymentOutcomes $outcomes) {}

    public static function hook(): string
    {
        return 'orillacart_forget_payment_events';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->outcomes->forgetOldEvents();
    }
}
