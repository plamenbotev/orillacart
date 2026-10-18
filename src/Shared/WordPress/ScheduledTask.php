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

    /** The plugin's own recurrence, for work that should not wait: once a minute, as often as WP-Cron runs. */
    public const string EVERY_MINUTE = 'orillacart_every_minute';

    /** The cron hook, unique: "orillacart_forget_abandoned_carts". */
    public static function hook(): string;

    /** A WordPress recurrence ("hourly", "twicedaily", "daily", "weekly") or self::EVERY_MINUTE. */
    public static function recurrence(): string;

    public function run(): void;
}
