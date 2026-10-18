<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\JobHandlers;
use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every minute: runs due jobs for up to twenty seconds, in the lanes no long-running worker serves
 * (wp orillacart jobs work --loop), and notes that WP-Cron ran, for the overview.
 */
final readonly class RunJobs implements ScheduledTask
{
    private const int SECONDS = 20;
    private const int BATCH = 50;

    public function __construct(
        private JobRunner $runner,
        private JobWorkers $workers,
        private JobHandlers $handlers,
    ) {}

    public static function hook(): string
    {
        return 'orillacart_run_jobs';
    }

    public static function recurrence(): string
    {
        return self::EVERY_MINUTE;
    }

    public function run(): void
    {
        $this->workers->cronRan();
        $lanes = $this->workers->unserved($this->handlers->queues());
        if ($lanes === []) {
            return;
        }
        $until = microtime(true) + self::SECONDS;
        do {
            $run = $this->runner->work($lanes, self::BATCH, 'cron:' . getmypid());
        } while ($run->count() > 0 && microtime(true) < $until);
    }
}
