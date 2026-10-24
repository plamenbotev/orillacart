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
    /** Small: a batch is done or tried again as one, and a host's time limit cut through a big one again and again. */
    private const int BATCH = 10;

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
        // A slow batch (PDFs, the search index) may run past twenty seconds: the host's limit must not stop it halfway,
        // or every job in it counts a failed try.
        if (\function_exists('set_time_limit')) {
            set_time_limit(self::SECONDS + 60);
        }
        $until = microtime(true) + self::SECONDS;
        do {
            $run = $this->runner->work($lanes, self::BATCH, 'cron:' . getmypid());
        } while ($run->count() > 0 && microtime(true) < $until);
    }
}
