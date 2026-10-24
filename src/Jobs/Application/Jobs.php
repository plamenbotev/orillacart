<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\FailedJobView;
use OrillaCart\Jobs\Application\Dto\NewJob;
use OrillaCart\Jobs\Application\Dto\WaitingJobs;
use OrillaCart\Shared\Lifecycle\AfterResponse;
use Psr\Clock\ClockInterface;

/**
 * Where the shop's areas push work for later. Pushed inside a transaction, a job is part of it. A request that
 * pushed jobs runs a few of them after its response, where the server sends the response first and unless
 * long-running workers serve the queue; WP-Cron and workers run the rest.
 */
final readonly class Jobs
{
    /** How many jobs a request runs after its response, at most. */
    private const int AFTER_RESPONSE = 20;
    /** History kept: hourly counts, failed jobs, workers that stopped without a word. */
    private const string KEEP_STATS = '-90 days';
    private const string KEEP_FAILED = '-30 days';
    private const string KEEP_WORKERS = '-1 day';

    public function __construct(
        private JobQueue $queue,
        private JobHandlers $handlers,
        private ClockInterface $clock,
        private AfterResponse $afterResponse,
        private JobRunner $runner,
        private JobWorkers $workers,
        private FailedJobs $failed,
        private WorkerRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param string|null          $uniqueKey one pending job per type and key: pushing it again counts up its version
     *                                        and gives it this payload
     * @param \DateTimeImmutable|null $at     not before then; now when null
     */
    public function push(string $type, array $payload = [], ?string $uniqueKey = null, ?\DateTimeImmutable $at = null): void
    {
        $this->pushMany($type, [['key' => $uniqueKey, 'payload' => $payload]], $at);
    }

    /**
     * Many jobs of one type, in a few statements.
     *
     * @param list<array{key: ?string, payload: array<string, mixed>}> $jobs
     */
    public function pushMany(string $type, array $jobs, ?\DateTimeImmutable $at = null): void
    {
        if ($jobs === []) {
            return;
        }
        $queue = $this->handlers->get($type)::queue();
        $now = $this->clock->now();
        $this->queue->push(array_map(static fn(array $job): NewJob => new NewJob($queue, $type, $job['payload'], $job['key'], $at ?? $now), $jobs), $now);
        if (!$this->afterResponse->detaches()) {
            return;
        }
        $this->afterResponse->defer(self::class, function (): void {
            $lanes = $this->workers->unserved($this->handlers->queues());
            if ($lanes !== []) {
                $this->runner->work($lanes, self::AFTER_RESPONSE, 'request:' . getmypid());
            }
        });
    }

    /** How many jobs of the type wait, due now or later. */
    public function pending(string $type): int
    {
        return $this->queue->pending($type);
    }

    /** What of the type waits: due, running, scheduled ahead. */
    public function waiting(string $type): WaitingJobs
    {
        return $this->queue->waitingByType($this->clock->now())[$type] ?? new WaitingJobs();
    }

    /** How many of the type's jobs failed and wait to be retried. */
    public function failedCount(string $type): int
    {
        return $this->failed->count($type);
    }

    /**
     * The type's latest failed jobs, each with what it was for.
     *
     * @return list<FailedJobView>
     */
    public function failed(string $type, int $limit = 50): array
    {
        $handler = $this->handlers->get($type);

        return array_map(static fn(FailedJobView $job): FailedJobView => $job->about($handler->describe($job->payload, $job->uniqueKey)), $this->failed->list($type, $limit));
    }

    /**
     * Puts failed jobs back in their queue with fresh attempts.
     *
     * @param list<string>|null $ids null: all of them
     *
     * @return int how many
     */
    public function retryFailed(?array $ids): int
    {
        return $this->failed->retry($ids, $this->clock->now());
    }

    /**
     * @param list<string>|null $ids null: all of them
     *
     * @return int how many
     */
    public function forgetFailed(?array $ids): int
    {
        return $this->failed->forget($ids);
    }

    /** Trims the history, daily. */
    public function prune(): void
    {
        $now = $this->clock->now();
        $this->queue->pruneStats($now->modify(self::KEEP_STATS));
        $keep = array_values(array_filter($this->handlers->types(), fn(string $type): bool => $this->handlers->get($type) instanceof KeepsFailures));
        $this->failed->prune($now->modify(self::KEEP_FAILED), $keep);
        $this->registry->prune($now->modify(self::KEEP_WORKERS));
    }
}
