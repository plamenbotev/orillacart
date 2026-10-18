<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

use OrillaCart\Search\Application\Dto\ClaimedJob;
use OrillaCart\Search\Application\Dto\QueueStatus;
use OrillaCart\Search\Application\IndexQueue;
use OrillaCart\Search\Domain\IndexJob;

/**
 * The queue in the index_jobs table. Pushing upserts one row per product and counts up its version; workers
 * claim rows with FOR UPDATE SKIP LOCKED (MySQL 8.0, MariaDB 10.6), so each takes different products, and
 * complete a row only at the version they claimed, so a change made while building is built again.
 */
final readonly class DbalIndexQueue implements IndexQueue
{
    private const string MOMENT = 'Y-m-d H:i:s';
    private const int CHUNK = 500;

    public function __construct(private SearchTables $tables) {}

    public function push(array $productIds, \DateTimeImmutable $at): void
    {
        $connection = $this->tables->connection();
        $moment = $at->format(self::MOMENT);
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            $rows = implode(', ', array_fill(0, \count($chunk), '(?, 1, ?, 0)'));
            $params = [];
            foreach ($chunk as $productId) {
                array_push($params, $productId, $moment);
            }
            // LEAST: a product waiting for a sale to start is due now that it changed.
            $connection->executeStatement(
                "INSERT INTO {$this->tables->jobs()} (product_id, version, available_at, attempts) VALUES {$rows}
                 ON DUPLICATE KEY UPDATE version = version + 1, available_at = LEAST(available_at, ?), attempts = 0, last_error = NULL",
                [...$params, $moment],
            );
        }
    }

    public function claim(int $limit, \DateTimeImmutable $now, \DateTimeImmutable $lockedUntil): array
    {
        $connection = $this->tables->connection();
        $jobs = $this->tables->jobs();
        $moment = $now->format(self::MOMENT);

        return $connection->transactional(static function () use ($connection, $jobs, $moment, $limit, $lockedUntil): array {
            $rows = $connection->fetchAllAssociative(
                \sprintf(
                    'SELECT product_id, version FROM %s WHERE available_at <= ? AND (locked_until IS NULL OR locked_until <= ?) AND attempts < %d
                     ORDER BY available_at, product_id LIMIT %d FOR UPDATE SKIP LOCKED',
                    $jobs,
                    IndexJob::MAX_ATTEMPTS,
                    max(1, $limit),
                ),
                [$moment, $moment],
            );
            $claimed = array_map(static fn(array $row): ClaimedJob => new ClaimedJob(self::int($row['product_id']), self::int($row['version'])), $rows);
            if ($claimed !== []) {
                $ids = array_map(static fn(ClaimedJob $job): int => $job->productId, $claimed);
                $connection->executeStatement(
                    "UPDATE {$jobs} SET locked_until = ? WHERE product_id IN (" . implode(', ', array_fill(0, \count($ids), '?')) . ')',
                    [$lockedUntil->format(self::MOMENT), ...$ids],
                );
            }

            return $claimed;
        });
    }

    public function complete(ClaimedJob $job, ?\DateTimeImmutable $next): void
    {
        $connection = $this->tables->connection();
        $done = $next === null
            ? $connection->executeStatement("DELETE FROM {$this->tables->jobs()} WHERE product_id = ? AND version = ?", [$job->productId, $job->version])
            : $connection->executeStatement(
                "UPDATE {$this->tables->jobs()} SET available_at = ?, locked_until = NULL, attempts = 0, last_error = NULL WHERE product_id = ? AND version = ?",
                [$next->format(self::MOMENT), $job->productId, $job->version],
            );
        if ($done === 0) {
            $this->release($job);
        }
    }

    public function fail(ClaimedJob $job, string $error, \DateTimeImmutable $retryAt): void
    {
        $counted = $this->tables->connection()->executeStatement(
            "UPDATE {$this->tables->jobs()} SET attempts = attempts + 1, last_error = ?, locked_until = NULL, available_at = ? WHERE product_id = ? AND version = ?",
            [mb_substr($error, 0, 255), $retryAt->format(self::MOMENT), $job->productId, $job->version],
        );
        if ($counted === 0) {
            $this->release($job);
        }
    }

    public function status(\DateTimeImmutable $now): QueueStatus
    {
        $connection = $this->tables->connection();
        $jobs = $this->tables->jobs();
        $moment = $now->format(self::MOMENT);
        $counts = $connection->fetchAssociative(
            \sprintf(
                'SELECT COALESCE(SUM(available_at <= ?), 0) AS due, COALESCE(SUM(available_at > ?), 0) AS later, MIN(CASE WHEN available_at > ? THEN available_at END) AS next_at
                 FROM %s WHERE attempts < %d',
                $jobs,
                IndexJob::MAX_ATTEMPTS,
            ),
            [$moment, $moment, $moment],
        );
        $parked = [];
        foreach ($connection->fetchAllAssociative(\sprintf('SELECT product_id, last_error FROM %s WHERE attempts >= %d ORDER BY product_id LIMIT 50', $jobs, IndexJob::MAX_ATTEMPTS)) as $row) {
            $parked[self::int($row['product_id'])] = \is_string($row['last_error']) ? $row['last_error'] : '';
        }
        $next = $counts === false ? null : $counts['next_at'];

        return new QueueStatus(
            $counts === false ? 0 : self::int($counts['due']),
            $counts === false ? 0 : self::int($counts['later']),
            $parked,
            \is_string($next) ? new \DateTimeImmutable($next) : null,
        );
    }

    /** The product changed while it was built: leave it for the next worker, due as the change made it. */
    private function release(ClaimedJob $job): void
    {
        $this->tables->connection()->executeStatement("UPDATE {$this->tables->jobs()} SET locked_until = NULL WHERE product_id = ?", [$job->productId]);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
