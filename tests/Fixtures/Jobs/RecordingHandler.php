<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Jobs;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;

/**
 * A handler that records the batches it was given and does what the test tells it per key: throw, or return an
 * outcome.
 */
final class RecordingHandler implements JobHandler
{
    /** @var list<list<?string>> the keys of each batch */
    public array $batches = [];
    /** @var array<string, JobOutcome|\Throwable> by key */
    public array $outcomes = [];
    public int $lock = 300;
    /** @var (\Closure(list<QueuedJob>): void)|null looks at the jobs while they run */
    public ?\Closure $during = null;

    public static function type(): string
    {
        return 'test.record';
    }

    public static function queue(): string
    {
        return 'test';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function retryAfter(int $attempt): int
    {
        return 10 * $attempt;
    }

    public function lockSeconds(): int
    {
        return $this->lock;
    }

    public function handle(array $jobs): array
    {
        $this->batches[] = array_map(static fn(QueuedJob $job): ?string => $job->uniqueKey, $jobs);
        if ($this->during !== null) {
            ($this->during)($jobs);
        }
        $outcomes = [];
        foreach ($jobs as $job) {
            $outcome = $this->outcomes[(string) $job->uniqueKey] ?? null;
            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }
            if ($outcome !== null) {
                $outcomes[$job->id] = $outcome;
            }
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return "Record {$uniqueKey}";
    }
}
