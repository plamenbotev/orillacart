<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\WorkerView;

/**
 * What workers tell the shop, read as it is now (not from a per-request cache): each worker's last sign of life,
 * when they were asked to restart, and when WP-Cron last ran the jobs.
 */
interface WorkerRegistry
{
    /** @param list<string> $queues */
    public function heartbeat(string $worker, array $queues, \DateTimeImmutable $startedAt, \DateTimeImmutable $now, ?string $working, int $jobsDone, int $memory): void;

    public function forget(string $worker): void;

    /** @return list<WorkerView> seen since $since, by name */
    public function workers(\DateTimeImmutable $since): array;

    /** Removes workers not seen since $before. */
    public function prune(\DateTimeImmutable $before): void;

    public function restartRequestedAt(): ?\DateTimeImmutable;

    public function requestRestart(\DateTimeImmutable $at): void;

    public function cronRanAt(): ?\DateTimeImmutable;

    public function cronRan(\DateTimeImmutable $at): void;

    /** Whether the site turned off WP-Cron's runs on visits (DISABLE_WP_CRON): a server's cron has to run it. */
    public function cronDisabled(): bool;
}
