<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Application\IndexWorker;
use OrillaCart\Search\Application\IndexWorkers;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every minute: builds the search documents of the products queued, for up to twenty seconds, unless workers
 * that run all the time (wp orillacart index work --loop) have the queue.
 */
final readonly class BuildSearchIndex implements ScheduledTask
{
    private const int SECONDS = 20;

    public function __construct(
        private IndexWorker $worker,
        private IndexWorkers $workers,
    ) {}

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
        // Workers that run all the time have the queue.
        if ($this->workers->running() > 0) {
            return;
        }
        $until = microtime(true) + self::SECONDS;
        do {
            $run = $this->worker->work();
        } while ($run->count() > 0 && microtime(true) < $until);
    }
}
