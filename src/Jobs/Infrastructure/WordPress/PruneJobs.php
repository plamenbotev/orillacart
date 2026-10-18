<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every day: trims the jobs' history, failed jobs nobody looked at, and workers that stopped without a word.
 */
final readonly class PruneJobs implements ScheduledTask
{
    public function __construct(private Jobs $jobs) {}

    public static function hook(): string
    {
        return 'orillacart_prune_jobs';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->jobs->prune();
    }
}
