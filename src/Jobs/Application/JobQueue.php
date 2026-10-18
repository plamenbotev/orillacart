<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\NewJob;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\Dto\WaitingJobs;

/**
 * Where jobs wait. Pushing inside a database transaction makes the job part of it: a job about an order exists
 * only if the order does. Any number of workers claim at once, each different jobs, and counts an attempt as
 * it claims, so a worker that dies still used one.
 */
interface JobQueue
{
    /**
     * Adds the jobs. A job with a unique key whose type already has one pending counts up that one's version
     * and makes it due by the sooner of the two times, with its attempts reset.
     *
     * @param list<NewJob> $jobs
     */
    public function push(array $jobs, \DateTimeImmutable $now): void;

    /**
     * Takes due jobs of the lanes (all lanes when none are named), oldest due first, for a while.
     *
     * @param list<string> $queues
     *
     * @return list<QueuedJob>
     */
    public function claim(array $queues, int $limit, string $worker, \DateTimeImmutable $now, \DateTimeImmutable $lockedUntil): array;

    /**
     * The jobs are done: they leave the queue, unless pushed again while they ran, which leaves them to run again.
     *
     * @param list<QueuedJob> $jobs
     */
    public function complete(array $jobs): void;

    /** The job is done for now and is due again at $at, as a search document when a sale starts. */
    public function reschedule(QueuedJob $job, \DateTimeImmutable $at): void;

    /** The attempt failed; the job is tried again at $at. */
    public function retry(QueuedJob $job, string $error, \DateTimeImmutable $at): void;

    /** The job is given up: it moves to the failed jobs, for the shop to retry or delete. */
    public function fail(QueuedJob $job, string $error, \DateTimeImmutable $at): void;

    /**
     * Adds a run's counts to the hour's history, one statement per type.
     *
     * @param array<string, array{done: int, retried: int, failed: int, ms: int}> $byType
     */
    public function record(array $byType, \DateTimeImmutable $hour): void;

    /**
     * How many jobs of the type wait, due now or later.
     */
    public function pending(string $type): int;

    /**
     * What waits, per lane.
     *
     * @return array<string, WaitingJobs> by lane
     */
    public function waitingByQueue(\DateTimeImmutable $now): array;

    /**
     * What waits, per type.
     *
     * @return array<string, WaitingJobs> by type
     */
    public function waitingByType(\DateTimeImmutable $now): array;

    /**
     * Deletes the hourly counts before $before.
     */
    public function pruneStats(\DateTimeImmutable $before): void;
}
