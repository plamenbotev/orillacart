<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Jobs;

use OrillaCart\Jobs\Application\Dto\WorkerView;
use OrillaCart\Jobs\Application\WorkerRegistry;

final class MemoryWorkerRegistry implements WorkerRegistry
{
    public bool $cronOff = false;
    /** @var array<string, WorkerView> */
    public array $workers = [];
    public ?\DateTimeImmutable $restart = null;
    public ?\DateTimeImmutable $cron = null;

    public function heartbeat(string $worker, array $queues, \DateTimeImmutable $startedAt, \DateTimeImmutable $now, ?string $working, int $jobsDone, int $memory): void
    {
        $this->workers[$worker] = new WorkerView($worker, $queues, $startedAt->format(\DATE_ATOM), $now->format(\DATE_ATOM), $working, $jobsDone, $memory);
    }

    public function forget(string $worker): void
    {
        unset($this->workers[$worker]);
    }

    public function workers(\DateTimeImmutable $since): array
    {
        return array_values(array_filter($this->workers, static fn(WorkerView $worker): bool => new \DateTimeImmutable($worker->seenAt) >= $since));
    }

    public function prune(\DateTimeImmutable $before): void
    {
        $this->workers = array_filter($this->workers, static fn(WorkerView $worker): bool => new \DateTimeImmutable($worker->seenAt) >= $before);
    }

    public function restartRequestedAt(): ?\DateTimeImmutable
    {
        return $this->restart;
    }

    public function requestRestart(\DateTimeImmutable $at): void
    {
        $this->restart = $at;
    }

    public function cronRanAt(): ?\DateTimeImmutable
    {
        return $this->cron;
    }

    public function cronRan(\DateTimeImmutable $at): void
    {
        $this->cron = $at;
    }

    public function cronDisabled(): bool
    {
        return $this->cronOff;
    }
}
