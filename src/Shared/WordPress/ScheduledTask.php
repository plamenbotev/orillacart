<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Work WordPress runs on a schedule (WP-Cron), such as clearing abandoned carts. Scheduled while the plugin
 * is active; the task is built only when it runs.
 */
#[AutoconfigureTag(self::TAG)]
interface ScheduledTask
{
    public const string TAG = 'orillacart.scheduled_task';

    /** The cron hook, unique: "orillacart_forget_abandoned_carts". */
    public static function hook(): string;

    /** A WordPress recurrence: "hourly", "twicedaily", "daily" or "weekly". */
    public static function recurrence(): string;

    public function run(): void;
}
