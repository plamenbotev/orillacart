<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;

/**
 * How a handler tells the runner of each job as it finishes it. The runner settles the job at once (done, due
 * again, to retry or failed), so a worker killed mid-batch loses only the job it was on, and keeps hold of the
 * jobs still to run for as long as the handler said it needs per job, so a slow batch is not taken over and run
 * twice by another worker. A handler that does its jobs one by one reports each; one that does them all in one go
 * returns their outcomes from handle() instead, and a job not reported either way is done.
 */
final class JobProgress
{
    /** @var array<string, true> the ids of the jobs reported */
    private array $reported = [];

    /**
     * @param \Closure(QueuedJob, JobOutcome): void $settle writes the job's outcome
     * @param \Closure(): void                      $holdOn keeps the jobs still to run held
     */
    public function __construct(
        private readonly \Closure $settle,
        private readonly \Closure $holdOn,
    ) {}

    /** The job is finished: done when no outcome is given. A job reported twice is settled by its first report. */
    public function done(QueuedJob $job, ?JobOutcome $outcome = null): void
    {
        if (isset($this->reported[$job->id])) {
            return;
        }
        $this->reported[$job->id] = true;
        ($this->settle)($job, $outcome ?? JobOutcome::done());
        ($this->holdOn)();
    }

    /** Whether the handler reported the job already. */
    public function reported(QueuedJob $job): bool
    {
        return isset($this->reported[$job->id]);
    }
}
