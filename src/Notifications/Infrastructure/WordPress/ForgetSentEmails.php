<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\SendOrderEmails;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every day: forgets which email jobs went out a month ago.
 */
final readonly class ForgetSentEmails implements ScheduledTask
{
    public function __construct(private SendOrderEmails $emails) {}

    public static function hook(): string
    {
        return 'orillacart_forget_sent_emails';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->emails->forgetSent();
    }
}
