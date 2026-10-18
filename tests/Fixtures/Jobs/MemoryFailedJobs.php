<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Jobs;

use OrillaCart\Jobs\Application\Dto\FailedJobView;
use OrillaCart\Jobs\Application\Dto\NewJob;
use OrillaCart\Jobs\Application\FailedJobs;

/**
 * Failed jobs in memory, as DbalFailedJobs keeps them; a retry pushes them back to the queue.
 */
final class MemoryFailedJobs implements FailedJobs
{
    /** @var array<int, FailedJobView> */
    public array $jobs = [];
    private int $nextId = 1;

    public function __construct(private readonly MemoryJobQueue $queue) {}

    public function add(FailedJobView $job): void
    {
        $id = $this->nextId++;
        $this->jobs[$id] = new FailedJobView((string) $id, $job->queue, $job->type, $job->uniqueKey, $job->payload, $job->error, $job->attempts, $job->failedAt);
    }

    public function list(?string $type, int $limit, int $offset = 0): array
    {
        $failed = array_reverse(array_values(array_filter($this->jobs, static fn(FailedJobView $job): bool => $type === null || $job->type === $type)));

        return \array_slice($failed, $offset, $limit);
    }

    public function count(?string $type = null): int
    {
        return \count($this->list($type, PHP_INT_MAX));
    }

    public function retry(?array $ids, \DateTimeImmutable $now): int
    {
        $retried = array_filter($this->jobs, static fn(FailedJobView $job): bool => $ids === null || \in_array($job->id, $ids, true));
        $this->queue->push(array_values(array_map(static fn(FailedJobView $job): NewJob => new NewJob($job->queue, $job->type, $job->payload, $job->uniqueKey, $now), $retried)), $now);

        return $this->forget($ids);
    }

    public function forget(?array $ids): int
    {
        $count = 0;
        foreach ($this->jobs as $id => $job) {
            if ($ids === null || \in_array($job->id, $ids, true)) {
                unset($this->jobs[$id]);
                ++$count;
            }
        }

        return $count;
    }

    public function prune(\DateTimeImmutable $before): void
    {
        $this->jobs = array_filter($this->jobs, static fn(FailedJobView $job): bool => new \DateTimeImmutable($job->failedAt) >= $before);
    }
}
