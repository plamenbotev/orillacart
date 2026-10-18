<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Jobs;

use OrillaCart\Jobs\Application\Dto\FailedJobView;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\Dto\WaitingJobs;
use OrillaCart\Jobs\Application\JobQueue;

/**
 * The queue in memory, as DbalJobQueue keeps it; failed jobs move to $failed.
 */
final class MemoryJobQueue implements JobQueue
{
    /** @var array<int, array{queue: string, type: string, key: ?string, payload: array<string, mixed>, version: int, attempts: int, at: \DateTimeImmutable, lockedUntil: ?\DateTimeImmutable, error: ?string}> by id */
    public array $jobs = [];
    public MemoryFailedJobs $failed;
    /** @var array<string, array<string, array{done: int, retried: int, failed: int, ms: int}>> by hour, then type */
    public array $stats = [];
    private int $nextId = 1;

    public function __construct()
    {
        $this->failed = new MemoryFailedJobs($this);
    }

    public function push(array $jobs, \DateTimeImmutable $now): void
    {
        foreach ($jobs as $job) {
            $existing = $job->uniqueKey === null ? null : $this->find($job->type, $job->uniqueKey);
            if ($existing !== null) {
                $old = $this->jobs[$existing];
                $this->set($existing, ['version' => $old['version'] + 1, 'attempts' => 0, 'error' => null, 'at' => min($old['at'], $job->availableAt)]);
                continue;
            }
            $this->jobs[$this->nextId++] = ['queue' => $job->queue, 'type' => $job->type, 'key' => $job->uniqueKey, 'payload' => $job->payload, 'version' => 1, 'attempts' => 0, 'at' => $job->availableAt, 'lockedUntil' => null, 'error' => null];
        }
    }

    public function claim(array $queues, int $limit, string $worker, \DateTimeImmutable $now, \DateTimeImmutable $lockedUntil): array
    {
        $due = array_filter($this->jobs, static fn(array $job): bool => ($queues === [] || \in_array($job['queue'], $queues, true)) && $job['at'] <= $now && ($job['lockedUntil'] === null || $job['lockedUntil'] <= $now));
        uasort($due, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
        $claimed = [];
        foreach (\array_slice($due, 0, $limit, true) as $id => $job) {
            $this->set($id, ['lockedUntil' => $lockedUntil, 'attempts' => $job['attempts'] + 1]);
            $claimed[] = new QueuedJob((string) $id, $job['queue'], $job['type'], $job['key'], $job['payload'], $job['version'], $job['attempts'] + 1);
        }

        return $claimed;
    }

    public function complete(array $jobs): void
    {
        foreach ($jobs as $job) {
            if ($this->current($job)) {
                unset($this->jobs[(int) $job->id]);
            } else {
                $this->release($job);
            }
        }
    }

    public function reschedule(QueuedJob $job, \DateTimeImmutable $at): void
    {
        if ($this->current($job)) {
            $this->set((int) $job->id, ['at' => $at, 'attempts' => 0, 'lockedUntil' => null, 'error' => null]);
        } else {
            $this->release($job);
        }
    }

    public function retry(QueuedJob $job, string $error, \DateTimeImmutable $at): void
    {
        if ($this->current($job)) {
            $this->set((int) $job->id, ['at' => $at, 'lockedUntil' => null, 'error' => $error]);
        } else {
            $this->release($job);
        }
    }

    public function fail(QueuedJob $job, string $error, \DateTimeImmutable $at): void
    {
        if (!$this->current($job)) {
            $this->release($job);

            return;
        }
        unset($this->jobs[(int) $job->id]);
        $this->failed->add(new FailedJobView('', $job->queue, $job->type, $job->uniqueKey, $job->payload, $error, $job->attempts, $at->format(\DATE_ATOM)));
    }

    public function record(array $byType, \DateTimeImmutable $hour): void
    {
        foreach ($byType as $type => $counts) {
            $old = $this->stats[$hour->format(\DATE_ATOM)][$type] ?? ['done' => 0, 'retried' => 0, 'failed' => 0, 'ms' => 0];
            $this->stats[$hour->format(\DATE_ATOM)][$type] = ['done' => $old['done'] + $counts['done'], 'retried' => $old['retried'] + $counts['retried'], 'failed' => $old['failed'] + $counts['failed'], 'ms' => $old['ms'] + $counts['ms']];
        }
    }

    public function pending(string $type): int
    {
        return \count(array_filter($this->jobs, static fn(array $job): bool => $job['type'] === $type));
    }

    public function waitingByQueue(\DateTimeImmutable $now): array
    {
        return $this->waiting('queue', $now);
    }

    public function waitingByType(\DateTimeImmutable $now): array
    {
        return $this->waiting('type', $now);
    }

    public function pruneStats(\DateTimeImmutable $before): void
    {
        $this->stats = array_filter($this->stats, static fn(string $hour): bool => new \DateTimeImmutable($hour) >= $before, ARRAY_FILTER_USE_KEY);
    }

    private function find(string $type, string $key): ?int
    {
        foreach ($this->jobs as $id => $job) {
            if ($job['type'] === $type && $job['key'] === $key) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array{version?: int, attempts?: int, at?: \DateTimeImmutable, lockedUntil?: ?\DateTimeImmutable, error?: ?string} $changes
     */
    private function set(int $id, array $changes): void
    {
        $job = $this->jobs[$id];
        $this->jobs[$id] = [
            'queue' => $job['queue'],
            'type' => $job['type'],
            'key' => $job['key'],
            'payload' => $job['payload'],
            'version' => $changes['version'] ?? $job['version'],
            'attempts' => $changes['attempts'] ?? $job['attempts'],
            'at' => $changes['at'] ?? $job['at'],
            'lockedUntil' => \array_key_exists('lockedUntil', $changes) ? $changes['lockedUntil'] : $job['lockedUntil'],
            'error' => \array_key_exists('error', $changes) ? $changes['error'] : $job['error'],
        ];
    }

    private function current(QueuedJob $job): bool
    {
        return ($this->jobs[(int) $job->id]['version'] ?? null) === $job->version;
    }

    private function release(QueuedJob $job): void
    {
        if (isset($this->jobs[(int) $job->id])) {
            $this->set((int) $job->id, ['lockedUntil' => null]);
        }
    }

    /**
     * @return array<string, WaitingJobs>
     */
    private function waiting(string $by, \DateTimeImmutable $now): array
    {
        $counts = [];
        foreach ($this->jobs as $job) {
            $name = $by === 'queue' ? $job['queue'] : $job['type'];
            $count = $counts[$name] ?? ['due' => 0, 'running' => 0, 'later' => 0, 'next' => null];
            if ($job['lockedUntil'] !== null && $job['lockedUntil'] > $now) {
                ++$count['running'];
            } elseif ($job['at'] <= $now) {
                ++$count['due'];
            } else {
                ++$count['later'];
                $count['next'] = $count['next'] === null ? $job['at'] : min($count['next'], $job['at']);
            }
            $counts[$name] = $count;
        }

        return array_map(static fn(array $count): WaitingJobs => new WaitingJobs($count['due'], $count['running'], $count['later'], $count['next']?->format(\DATE_ATOM)), $counts);
    }
}
