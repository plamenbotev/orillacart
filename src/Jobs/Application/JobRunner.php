<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\JobRun;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Shared\Persistence\LoadedEntities;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Claims due jobs and hands them to their handlers, type by type, in batches. A handler that throws has its
 * batch run again job by job, so only the bad job is retried; a job is retried after its handler's wait while
 * attempts last, then failed for the shop to look at. WP-Cron, the end of requests and workers all run it, each
 * job as nobody and in the site's language.
 */
final readonly class JobRunner
{
    /** How long a worker holds what it claimed until it runs it; one that dies leaves it to others after this. */
    public const int LOCK_SECONDS = 300;

    public function __construct(
        private JobQueue $queue,
        private JobHandlers $handlers,
        private LoadedEntities $entities,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private JobContext $context,
    ) {}

    /**
     * @param list<string>                         $queues  the lanes to serve; none: every lane
     * @param (\Closure(list<QueuedJob>): void)|null $claimed told what was claimed, before it runs
     */
    public function work(array $queues, int $limit, string $worker, ?\Closure $claimed = null): JobRun
    {
        $now = $this->clock->now();
        $jobs = $this->queue->claim($queues, $limit, $worker, $now, $now->modify(\sprintf('+%d seconds', self::LOCK_SECONDS)));
        if ($jobs === []) {
            return new JobRun();
        }
        if ($claimed !== null) {
            $claimed($jobs);
        }
        // Read what the jobs are about as it is now, not as this process loaded it before.
        $this->entities->forget();

        $byType = [];
        foreach ($jobs as $job) {
            $byType[$job->type][] = $job;
        }
        $stats = [];
        $done = $retried = $failed = 0;
        foreach ($byType as $type => $group) {
            $started = microtime(true);
            $counts = $this->context->run(fn(): array => $this->runType((string) $type, $group));
            $stats[(string) $type] = $counts + ['ms' => (int) round((microtime(true) - $started) * 1000)];
            $done += $counts['done'];
            $retried += $counts['retried'];
            $failed += $counts['failed'];
        }
        $this->queue->record($stats, $now->setTime((int) $now->format('H'), 0));

        return new JobRun($done, $retried, $failed);
    }

    /**
     * @param non-empty-list<QueuedJob> $jobs
     *
     * @return array{done: int, retried: int, failed: int}
     */
    private function runType(string $type, array $jobs): array
    {
        try {
            $handler = $this->handlers->get($type);
        } catch (\LogicException $e) {
            foreach ($jobs as $job) {
                $this->queue->fail($job, $e->getMessage(), $this->clock->now());
            }

            return ['done' => 0, 'retried' => 0, 'failed' => \count($jobs)];
        }

        // A job claimed more often than its attempts allow was never finished: its worker stopped mid-way.
        $runnable = [];
        $failed = 0;
        foreach ($jobs as $job) {
            if ($job->attempts > $handler->maxAttempts()) {
                $this->queue->fail($job, \sprintf('Not finished in %d attempts: its worker stopped while running it.', $handler->maxAttempts()), $this->clock->now());
                ++$failed;
            } else {
                $runnable[] = $job;
            }
        }
        if ($runnable === []) {
            return ['done' => 0, 'retried' => 0, 'failed' => $failed];
        }
        $this->queue->hold($runnable, $this->clock->now()->modify(\sprintf('+%d seconds', $handler->lockSeconds())));

        try {
            $outcomes = $handler->handle($runnable);
        } catch (\Throwable $e) {
            // One job spoiled the batch: run them one by one, so only that one is retried.
            $this->logger->warning('A batch of {type} jobs failed, so each runs on its own: {message}', ['type' => $type, 'message' => $e->getMessage(), 'exception' => $e]);
            $outcomes = [];
            foreach ($runnable as $job) {
                try {
                    $this->queue->hold([$job], $this->clock->now()->modify(\sprintf('+%d seconds', $handler->lockSeconds())));
                    $outcomes += $handler->handle([$job]);
                } catch (\Throwable $e) {
                    $this->logger->error('Job {type} {key} failed: {message}', ['type' => $type, 'key' => $job->uniqueKey ?? $job->id, 'message' => $e->getMessage(), 'exception' => $e]);
                    $outcomes[$job->id] = JobOutcome::retry($e->getMessage());
                }
            }
        }

        $done = [];
        $rescheduled = $retried = 0;
        foreach ($runnable as $job) {
            $outcome = $outcomes[$job->id] ?? JobOutcome::done();
            $now = $this->clock->now();
            if ($outcome->isDone()) {
                $done[] = $job;
            } elseif ($outcome->isRescheduled() && $outcome->at !== null) {
                $this->queue->reschedule($job, $outcome->at);
                ++$rescheduled;
            } elseif ($outcome->isRetry() && $job->attempts < $handler->maxAttempts()) {
                $this->queue->retry($job, self::kept($outcome->error), $now->modify(\sprintf('+%d seconds', max(1, $handler->retryAfter($job->attempts)))));
                ++$retried;
            } else {
                $this->queue->fail($job, self::kept($outcome->error), $now);
                ++$failed;
            }
        }
        $this->queue->complete($done);

        return ['done' => \count($done) + $rescheduled, 'retried' => $retried, 'failed' => $failed];
    }

    /**
     * An error as the queue keeps it, for the shop to read for weeks: a message from deep down (a template, the
     * database) can carry a customer's email address, which it does not need.
     */
    private static function kept(string $error): string
    {
        return mb_substr((string) preg_replace('/[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+\.[a-z]{2,}/i', '[email]', $error), 0, 2000);
    }
}
