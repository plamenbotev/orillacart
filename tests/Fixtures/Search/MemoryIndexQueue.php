<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Search;

use OrillaCart\Search\Application\Dto\ClaimedJob;
use OrillaCart\Search\Application\Dto\QueueStatus;
use OrillaCart\Search\Application\IndexQueue;

/**
 * The queue for one process: no locking, the same answers.
 */
final class MemoryIndexQueue implements IndexQueue
{
    /** @var array<int, array{version: int, at: \DateTimeImmutable, attempts: int, error: ?string}> */
    public array $jobs = [];

    public function push(array $productIds, \DateTimeImmutable $at): void
    {
        foreach ($productIds as $id) {
            $job = $this->jobs[$id] ?? null;
            $this->jobs[$id] = ['version' => ($job['version'] ?? 0) + 1, 'at' => $job === null || $at < $job['at'] ? $at : $job['at'], 'attempts' => 0, 'error' => null];
        }
    }

    public function claim(int $limit, \DateTimeImmutable $now, \DateTimeImmutable $lockedUntil): array
    {
        $due = array_filter($this->jobs, static fn(array $job): bool => $job['at'] <= $now && $job['attempts'] < 5);

        return \array_slice(array_map(static fn(int $id, array $job): ClaimedJob => new ClaimedJob($id, $job['version']), array_keys($due), $due), 0, $limit);
    }

    public function complete(ClaimedJob $job, ?\DateTimeImmutable $next): void
    {
        if ($next === null) {
            unset($this->jobs[$job->productId]);
        } else {
            $this->jobs[$job->productId] = ['at' => $next] + $this->jobs[$job->productId];
        }
    }

    public function fail(ClaimedJob $job, string $error, \DateTimeImmutable $retryAt): void
    {
        $kept = $this->jobs[$job->productId];
        $this->jobs[$job->productId] = ['version' => $kept['version'], 'at' => $retryAt, 'attempts' => $kept['attempts'] + 1, 'error' => $error];
    }

    public function status(\DateTimeImmutable $now): QueueStatus
    {
        return new QueueStatus(\count(array_filter($this->jobs, static fn(array $job): bool => $job['at'] <= $now)), 0, [], null);
    }
}
