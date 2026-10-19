<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\WorkerView;
use Psr\Clock\ClockInterface;

/**
 * Long-running workers (wp orillacart jobs work --loop), as many as the shop runs: they tell the shop they are
 * alive and what they do, so WP-Cron and the end of requests leave the queue to them, and they stop when asked to
 * restart, as after a deploy, so a supervisor starts them on the new code.
 */
final readonly class JobWorkers
{
    /** A worker unseen for this long is gone; WP-Cron takes the queue back. */
    public const int ALIVE_SECONDS = 120;
    /** How often a worker tells it is alive, at most. */
    public const int HEARTBEAT_SECONDS = 10;

    public function __construct(
        private WorkerRegistry $registry,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<string> $queues
     */
    public function heartbeat(string $worker, array $queues, \DateTimeImmutable $startedAt, ?string $working, int $jobsDone, int $memory): void
    {
        $this->registry->heartbeat($worker, $queues, $startedAt, $this->clock->now(), $working, $jobsDone, $memory);
    }

    public function stopped(string $worker): void
    {
        $this->registry->forget($worker);
    }

    /** Asks every running worker to finish its batch and stop. */
    public function restart(): void
    {
        $this->registry->requestRestart($this->clock->now());
    }

    public function shouldRestart(\DateTimeImmutable $startedAt): bool
    {
        $requested = $this->registry->restartRequestedAt();

        return $requested !== null && $requested >= $startedAt;
    }

    /** @return list<WorkerView> */
    public function workers(): array
    {
        return $this->registry->workers($this->clock->now()->modify(\sprintf('-%d seconds', self::ALIVE_SECONDS)));
    }

    public function running(): int
    {
        return \count($this->workers());
    }

    /** How many running workers serve the lane. */
    public function serving(string $queue): int
    {
        return \count(array_filter($this->workers(), static fn(WorkerView $worker): bool => $worker->queues === [] || \in_array($queue, $worker->queues, true)));
    }

    /**
     * The lanes no running worker serves, which WP-Cron and the end of requests run instead.
     *
     * @param list<string> $queues every lane
     *
     * @return list<string>
     */
    public function unserved(array $queues): array
    {
        foreach ($this->workers() as $worker) {
            if ($worker->queues === []) {
                return [];
            }
            $queues = array_values(array_diff($queues, $worker->queues));
        }

        return $queues;
    }

    public function cronRan(): void
    {
        $this->registry->cronRan($this->clock->now());
    }

    public function cronRanAt(): ?\DateTimeImmutable
    {
        return $this->registry->cronRanAt();
    }

    public function cronDisabled(): bool
    {
        return $this->registry->cronDisabled();
    }
}
