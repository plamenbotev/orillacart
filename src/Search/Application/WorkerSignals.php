<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

/**
 * What long-running index workers tell each other and the shop, kept where every process sees it at once
 * (not in a per-request cache): when they were asked to restart, and when each was last seen.
 */
interface WorkerSignals
{
    public function restartRequestedAt(): ?\DateTimeImmutable;

    public function requestRestart(\DateTimeImmutable $at): void;

    /** The worker is alive; entries older than $forgetBefore are dropped. */
    public function seen(string $worker, \DateTimeImmutable $at, \DateTimeImmutable $forgetBefore): void;

    /**
     * @return array<string, \DateTimeImmutable> when each worker was last seen
     */
    public function workers(): array;
}
