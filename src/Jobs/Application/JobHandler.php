<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;

/**
 * Does one type of job. Workers hand it the jobs of its type they claimed together, so it can read and write
 * for many at once. A handler that throws has its batch run again job by job, so only the bad job is retried.
 * Tagged by the Kernel, so the container finds every handler.
 */
interface JobHandler
{
    public const string TAG = 'orillacart.job_handler';

    /** The job type, unique: "search.index". */
    public static function type(): string;

    /** The lane its jobs wait in; workers can serve some lanes only: "search", "mail". */
    public static function queue(): string;

    /** How many times a job is tried before it is failed for the shop to look at. */
    public function maxAttempts(): int;

    /** Seconds before trying again after the given failed attempt (1, 2, …); grow it, so a provider can recover. */
    public function retryAfter(int $attempt): int;

    /**
     * @param non-empty-list<QueuedJob> $jobs of this handler's type
     *
     * @return array<string, JobOutcome> by job id; a job left out is done
     */
    public function handle(array $jobs): array;

    /**
     * What a job is for, for the overview: "Order #1042: order received email".
     *
     * @param array<string, mixed> $payload
     */
    public function describe(array $payload, ?string $uniqueKey): string;
}
