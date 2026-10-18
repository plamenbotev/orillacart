<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use Psr\Clock\ClockInterface;

/**
 * Long-running index workers (wp orillacart index work --loop), as many as the shop runs: they tell the shop
 * they are alive, so cron and the end of requests leave the queue to them, and they stop when asked to restart,
 * as after a deploy, so a supervisor starts them on the new code.
 */
final readonly class IndexWorkers
{
    /** A worker unseen for this long is gone; cron takes the queue back. */
    public const int ALIVE_SECONDS = 120;
    /** How often a worker tells it is alive, at most. */
    public const int HEARTBEAT_SECONDS = 30;

    public function __construct(
        private WorkerSignals $signals,
        private ClockInterface $clock,
    ) {}

    /** Asks every running worker to finish its batch and stop. */
    public function restart(): void
    {
        $this->signals->requestRestart($this->clock->now());
    }

    /** Whether a restart was asked for since the worker started. */
    public function shouldRestart(\DateTimeImmutable $startedAt): bool
    {
        $requested = $this->signals->restartRequestedAt();

        return $requested !== null && $requested >= $startedAt;
    }

    public function heartbeat(string $worker): void
    {
        $now = $this->clock->now();
        $this->signals->seen($worker, $now, $now->modify(\sprintf('-%d seconds', self::ALIVE_SECONDS)));
    }

    /** How many workers were seen lately. */
    public function running(): int
    {
        $since = $this->clock->now()->modify(\sprintf('-%d seconds', self::ALIVE_SECONDS));

        return \count(array_filter($this->signals->workers(), static fn(\DateTimeImmutable $seen): bool => $seen >= $since));
    }
}
