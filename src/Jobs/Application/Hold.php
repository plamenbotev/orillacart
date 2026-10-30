<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\QueuedJob;

/**
 * The jobs a worker holds while a batch runs, and until when. Each time a job finishes the hold is extended for
 * the jobs still to run, once less than half of it is left: a batch that takes longer than one job's lock is kept
 * from other workers for as long as it goes on, without a write to the queue after every job.
 */
final class Hold
{
    /** @var array<string, QueuedJob> by id */
    private array $jobs = [];

    /**
     * @param list<QueuedJob>    $jobs    held until $until
     * @param \DateTimeImmutable $until   when the hold they have now ends
     * @param int                $seconds how long each extension holds them
     */
    public function __construct(
        private readonly JobQueue $queue,
        array $jobs,
        private \DateTimeImmutable $until,
        private readonly int $seconds,
    ) {
        foreach ($jobs as $job) {
            $this->jobs[$job->id] = $job;
        }
    }

    /**
     * The jobs are settled, or about to run under a hold of their own: no longer this one's.
     *
     * @param list<QueuedJob> $jobs
     */
    public function release(array $jobs): void
    {
        foreach ($jobs as $job) {
            unset($this->jobs[$job->id]);
        }
    }

    /** Holds the jobs left for $seconds from now, when less than half of their hold is left. */
    public function extend(\DateTimeImmutable $now): void
    {
        if ($this->jobs === []) {
            return;
        }
        $left = $this->until->getTimestamp() - $now->getTimestamp();
        if ($left * 2 >= $this->seconds) {
            return;
        }
        $this->until = $now->modify(\sprintf('+%d seconds', $this->seconds));
        $this->queue->hold(array_values($this->jobs), $this->until);
    }
}
