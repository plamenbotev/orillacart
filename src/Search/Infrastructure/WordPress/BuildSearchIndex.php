<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Application\IndexWorker;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every minute: builds the search documents of the products queued, for up to twenty seconds. Sites with a real
 * cron or a supervisor can run `wp orillacart index work` instead, or as well.
 */
final readonly class BuildSearchIndex implements ScheduledTask
{
    private const int SECONDS = 20;

    public function __construct(private IndexWorker $worker) {}

    public static function hook(): string
    {
        return 'orillacart_build_search_index';
    }

    public static function recurrence(): string
    {
        return self::EVERY_MINUTE;
    }

    public function run(): void
    {
        $until = microtime(true) + self::SECONDS;
        do {
            $run = $this->worker->work();
        } while ($run->count() > 0 && microtime(true) < $until);
    }
}
