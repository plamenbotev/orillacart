<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\WordPress;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Daily: forgets the changes older than ChangeLog::KEEP_DAYS.
 */
final readonly class ForgetOldChanges implements ScheduledTask
{
    public function __construct(private ChangeLog $log) {}

    public static function hook(): string
    {
        return 'orillacart_forget_old_changes';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->log->forgetOld();
    }
}
