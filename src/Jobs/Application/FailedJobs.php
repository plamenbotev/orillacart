<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\FailedJobView;

/**
 * Jobs given up after their attempts, newest first, until the shop retries or deletes them.
 */
interface FailedJobs
{
    /**
     * @return list<FailedJobView>
     */
    public function list(?string $type, int $limit, int $offset = 0): array;

    public function count(?string $type = null): int;

    /**
     * Puts the failed jobs back in their queue, due now with fresh attempts, and forgets them as failed.
     *
     * @param list<string>|null $ids null: every failed job
     *
     * @return int how many
     */
    public function retry(?array $ids, \DateTimeImmutable $now): int;

    /**
     * @param list<string>|null $ids null: every failed job
     *
     * @return int how many
     */
    public function forget(?array $ids): int;

    /** Deletes the failed jobs older than $before. */
    public function prune(\DateTimeImmutable $before): void;

    /**
     * Forgets failed jobs whose payload or error mentions the text, such as an email a privacy request erases.
     *
     * @return int how many
     */
    public function forgetMentioning(string $text): int;
}
