<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Ordering\Application\OrderService;

/**
 * Counts every order again, for a shop that had orders before the reports, or after the time zone changed. Each
 * run queues the next thousand orders and the run after them, so it walks a million orders without holding a
 * request or a worker for long.
 */
final readonly class RebuildReports implements JobHandler
{
    public const string TYPE = 'reports.rebuild';
    /** Every step is the same job, so there is one rebuild at a time. */
    public const string KEY = 'all';
    private const int BATCH = 1000;

    public function __construct(
        private OrderService $orders,
        private Jobs $jobs,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return ProjectOrders::queue();
    }

    public function maxAttempts(): int
    {
        return 5;
    }

    public function retryAfter(int $attempt): int
    {
        return 30 * 2 ** ($attempt - 1);
    }

    /** One step reads a thousand order ids and queues a job for each. */
    public function lockSeconds(): int
    {
        return 1800;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        // Each step is settled as soon as its orders are queued: run again, it would queue them twice.
        foreach ($jobs as $job) {
            $this->step($job);
            $progress->done($job);
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        $after = $payload['after'] ?? 0;

        return \sprintf('Counting the orders after #%d again', \is_int($after) ? $after : 0);
    }

    private function step(QueuedJob $job): void
    {
        $after = $job->payload['after'] ?? 0;
        $ids = $this->orders->ids(\is_int($after) ? $after : 0, self::BATCH);
        $this->jobs->pushMany(ProjectOrders::TYPE, array_map(static fn(int $id): array => ['key' => (string) $id, 'payload' => []], $ids));
        if (\count($ids) === self::BATCH) {
            $this->jobs->push(self::TYPE, ['after' => $ids[\count($ids) - 1]], self::KEY);
        }
    }
}
