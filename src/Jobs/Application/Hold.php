<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\QueuedJob;

/**
 * The jobs a worker holds while a batch runs, and until when. Before each job of a batch the jobs still to run are
 * held for a full lock again (renew), so the next job has all of its handler's lock however long the earlier ones
 * took: one write per job. The jobs of the types waiting their turn are extended only once less than half of their
 * hold is left (extend), as they need to be held, not given a job's time.
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
        if ($this->left($now) * 2 < $this->seconds) {
            $this->holdFrom($now);
        }
    }

    /** Holds the jobs left for the full $seconds from now, unless they are held that long already: a job starts. */
    public function renew(\DateTimeImmutable $now): void
    {
        if ($this->left($now) < $this->seconds) {
            $this->holdFrom($now);
        }
    }

    private function left(\DateTimeImmutable $now): int
    {
        return $this->until->getTimestamp() - $now->getTimestamp();
    }

    private function holdFrom(\DateTimeImmutable $now): void
    {
        if ($this->jobs === []) {
            return;
        }
        $this->until = $now->modify(\sprintf('+%d seconds', $this->seconds));
        $this->queue->hold(array_values($this->jobs), $this->until);
    }
}
