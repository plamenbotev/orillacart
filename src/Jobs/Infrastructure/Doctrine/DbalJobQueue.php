<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use OrillaCart\Jobs\Application\Dto\JobCounts;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\Dto\ScheduledJobView;
use OrillaCart\Jobs\Application\Dto\WaitingJobs;
use OrillaCart\Jobs\Application\JobQueue;

/**
 * The queue in the jobs table (MySQL 8.0, MariaDB 10.6). Unique jobs upsert one row per type and key and count
 * up its version; workers claim with FOR UPDATE SKIP LOCKED in a short transaction, so each takes different
 * jobs and none waits for another, and finish a job only at the version they claimed. Every write is a few
 * statements for a whole batch, whatever its size.
 */
final readonly class DbalJobQueue implements JobQueue
{
    private const string MOMENT = JobTables::MOMENT;
    private const int CHUNK = 500;

    public function __construct(private JobTables $tables) {}

    public function push(array $jobs, \DateTimeImmutable $now): void
    {
        $connection = $this->tables->connection();
        $created = $now->format(self::MOMENT);
        $unique = [];
        $plain = [];
        foreach ($jobs as $job) {
            $row = [$job->queue, $job->type, $job->uniqueKey, (string) wp_json_encode((object) $job->payload), $job->availableAt->format(self::MOMENT), $created];
            if ($job->uniqueKey === null) {
                $plain[] = $row;
            } else {
                // Grouped by due time: the upsert keeps the sooner of the pending job's and this one's.
                $unique[$row[4]][] = $row;
            }
        }
        $columns = '(queue, type, unique_key, payload, available_at, created_at, version, attempts)';
        $placeholders = static fn(int $count): string => implode(', ', array_fill(0, $count, '(?, ?, ?, ?, ?, ?, 1, 0)'));
        foreach (array_chunk($plain, self::CHUNK) as $chunk) {
            $connection->executeStatement("INSERT INTO {$this->tables->jobs()} {$columns} VALUES {$placeholders(\count($chunk))}", array_merge(...$chunk));
        }
        foreach ($unique as $at => $rows) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $connection->executeStatement(
                    "INSERT INTO {$this->tables->jobs()} {$columns} VALUES {$placeholders(\count($chunk))}
                     ON DUPLICATE KEY UPDATE version = version + 1, payload = VALUES(payload), available_at = LEAST(available_at, ?), attempts = 0, last_error = NULL",
                    [...array_merge(...$chunk), (string) $at],
                );
            }
        }
    }

    public function hold(array $jobs, \DateTimeImmutable $until): void
    {
        if ($jobs === []) {
            return;
        }
        $ids = array_map(static fn(QueuedJob $job): int => (int) $job->id, $jobs);
        $this->tables->connection()->executeStatement(
            "UPDATE {$this->tables->jobs()} SET locked_until = ? WHERE id IN (" . implode(', ', array_fill(0, \count($ids), '?')) . ')',
            [$until->format(self::MOMENT), ...$ids],
        );
    }

    public function claim(array $queues, int $limit, string $worker, \DateTimeImmutable $now, \DateTimeImmutable $lockedUntil): array
    {
        $connection = $this->tables->connection();
        $jobs = $this->tables->jobs();
        $moment = $now->format(self::MOMENT);

        return $connection->transactional(static function (Connection $connection) use ($jobs, $queues, $moment, $limit, $worker, $lockedUntil): array {
            $lanes = $queues === [] ? '' : 'queue IN (' . implode(', ', array_fill(0, \count($queues), '?')) . ') AND ';
            $rows = $connection->fetchAllAssociative(
                \sprintf(
                    'SELECT id, queue, type, unique_key, payload, version, attempts FROM %s
                     WHERE %savailable_at <= ? AND (locked_until IS NULL OR locked_until <= ?)
                     ORDER BY available_at, id LIMIT %d FOR UPDATE SKIP LOCKED',
                    $jobs,
                    $lanes,
                    max(1, $limit),
                ),
                [...$queues, $moment, $moment],
            );
            if ($rows === []) {
                return [];
            }
            $ids = array_map(static fn(array $row): int => self::int($row['id']), $rows);
            $connection->executeStatement(
                "UPDATE {$jobs} SET locked_until = ?, locked_by = ?, attempts = attempts + 1 WHERE id IN (" . implode(', ', array_fill(0, \count($ids), '?')) . ')',
                [$lockedUntil->format(self::MOMENT), mb_substr($worker, 0, 100), ...$ids],
            );

            return array_map(static fn(array $row): QueuedJob => new QueuedJob(
                (string) self::int($row['id']),
                \is_string($row['queue']) ? $row['queue'] : '',
                \is_string($row['type']) ? $row['type'] : '',
                \is_string($row['unique_key']) ? $row['unique_key'] : null,
                self::payload($row['payload']),
                self::int($row['version']),
                self::int($row['attempts']) + 1,
            ), $rows);
        });
    }

    public function complete(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }
        $connection = $this->tables->connection();
        foreach (array_chunk($jobs, self::CHUNK) as $chunk) {
            $connection->executeStatement(
                "DELETE FROM {$this->tables->jobs()} WHERE (id, version) IN (" . implode(', ', array_fill(0, \count($chunk), '(?, ?)')) . ')',
                array_merge(...array_map(static fn(QueuedJob $job): array => [(int) $job->id, $job->version], $chunk)),
            );
            // Pushed again while they ran: left for the next run, due as the push made them.
            $this->release(array_map(static fn(QueuedJob $job): int => (int) $job->id, $chunk));
            // The same work failed before: done now, so the shop is no longer told it is waiting.
            $keyed = array_values(array_filter($chunk, static fn(QueuedJob $job): bool => $job->uniqueKey !== null));
            if ($keyed !== []) {
                $connection->executeStatement(
                    "DELETE FROM {$this->tables->failed()} WHERE (type, unique_key) IN (" . implode(', ', array_fill(0, \count($keyed), '(?, ?)')) . ')',
                    array_merge(...array_map(static fn(QueuedJob $job): array => [$job->type, (string) $job->uniqueKey], $keyed)),
                );
            }
        }
    }

    public function reschedule(QueuedJob $job, \DateTimeImmutable $at): void
    {
        $changed = $this->tables->connection()->executeStatement(
            "UPDATE {$this->tables->jobs()} SET available_at = ?, attempts = 0, locked_until = NULL, locked_by = NULL, last_error = NULL WHERE id = ? AND version = ?",
            [$at->format(self::MOMENT), (int) $job->id, $job->version],
        );
        if ($changed === 0) {
            $this->release([(int) $job->id]);
        }
    }

    public function retry(QueuedJob $job, string $error, \DateTimeImmutable $at): void
    {
        $changed = $this->tables->connection()->executeStatement(
            "UPDATE {$this->tables->jobs()} SET available_at = ?, last_error = ?, locked_until = NULL, locked_by = NULL WHERE id = ? AND version = ?",
            [$at->format(self::MOMENT), $error, (int) $job->id, $job->version],
        );
        if ($changed === 0) {
            $this->release([(int) $job->id]);
        }
    }

    public function fail(QueuedJob $job, string $error, \DateTimeImmutable $at): void
    {
        $this->tables->connection()->transactional(function (Connection $connection) use ($job, $error, $at): void {
            $removed = $connection->executeStatement("DELETE FROM {$this->tables->jobs()} WHERE id = ? AND version = ?", [(int) $job->id, $job->version]);
            if ($removed === 0) {
                // Pushed again while it ran: the change deserves its own attempts.
                $this->release([(int) $job->id]);

                return;
            }
            $connection->insert($this->tables->failed(), [
                'queue' => $job->queue,
                'type' => $job->type,
                'unique_key' => $job->uniqueKey,
                'payload' => (string) wp_json_encode((object) $job->payload),
                'error' => $error === '' ? 'No reason given.' : $error,
                'attempts' => $job->attempts,
                'failed_at' => $at->format(self::MOMENT),
            ]);
        });
    }

    public function record(array $byType, \DateTimeImmutable $hour): void
    {
        foreach ($byType as $type => $counts) {
            $this->tables->connection()->executeStatement(
                "INSERT INTO {$this->tables->stats()} (hour, type, done, retried, failed, runtime_ms) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE done = done + ?, retried = retried + ?, failed = failed + ?, runtime_ms = runtime_ms + ?",
                [$hour->format(self::MOMENT), $type, $counts['done'], $counts['retried'], $counts['failed'], $counts['ms'], $counts['done'], $counts['retried'], $counts['failed'], $counts['ms']],
            );
        }
    }

    public function pending(string $type): int
    {
        return self::int($this->tables->connection()->fetchOne("SELECT COUNT(*) FROM {$this->tables->jobs()} WHERE type = ?", [$type]));
    }

    public function waitingByQueue(\DateTimeImmutable $now): array
    {
        return $this->waiting('queue', $now);
    }

    public function waitingByType(\DateTimeImmutable $now): array
    {
        return $this->waiting('type', $now);
    }

    public function scheduled(\DateTimeImmutable $now, int $limit): array
    {
        $moment = $now->format(self::MOMENT);
        $rows = $this->tables->connection()->fetchAllAssociative(
            \sprintf(
                'SELECT id, type, unique_key, payload, available_at FROM %s WHERE available_at > ? AND (locked_until IS NULL OR locked_until <= ?) ORDER BY available_at, id LIMIT %d',
                $this->tables->jobs(),
                max(1, $limit),
            ),
            [$moment, $moment],
        );

        return array_map(static fn(array $row): ScheduledJobView => new ScheduledJobView(
            (string) self::int($row['id']),
            \is_string($row['type']) ? $row['type'] : '',
            \is_string($row['unique_key']) ? $row['unique_key'] : null,
            self::payload($row['payload']),
            self::moment($row['available_at']) ?? '',
        ), $rows);
    }

    public function history(\DateTimeImmutable $since): array
    {
        $rows = $this->tables->connection()->fetchAllAssociative(
            "SELECT hour, type, done, retried, failed, runtime_ms FROM {$this->tables->stats()} WHERE hour >= ? ORDER BY hour, type",
            [$since->format(self::MOMENT)],
        );

        return array_map(static function (array $row): JobCounts {
            $jobs = self::int($row['done']) + self::int($row['retried']) + self::int($row['failed']);

            return new JobCounts(
                self::moment($row['hour']) ?? '',
                \is_string($row['type']) ? $row['type'] : '',
                self::int($row['done']),
                self::int($row['retried']),
                self::int($row['failed']),
                $jobs === 0 ? 0 : intdiv(self::int($row['runtime_ms']), $jobs),
            );
        }, $rows);
    }

    public function pruneStats(\DateTimeImmutable $before): void
    {
        $this->tables->connection()->executeStatement("DELETE FROM {$this->tables->stats()} WHERE hour < ?", [$before->format(self::MOMENT)]);
    }

    public function waitingOfType(string $type, \DateTimeImmutable $now): WaitingJobs
    {
        // job_type_waiting (type, available_at, locked_until) holds every column read: the type's rows only, from the index.
        return $this->waiting('type', $now, $type)[$type] ?? new WaitingJobs();
    }

    /**
     * @param 'queue'|'type' $by
     * @param string|null    $only the one name to count, for a WHERE on its index; all when null
     *
     * @return array<string, WaitingJobs>
     */
    private function waiting(string $by, \DateTimeImmutable $now, ?string $only = null): array
    {
        $moment = $now->format(self::MOMENT);
        $rows = $this->tables->connection()->fetchAllAssociative(
            "SELECT {$by} AS name,
                    SUM(available_at <= ? AND (locked_until IS NULL OR locked_until <= ?)) AS due,
                    SUM(locked_until > ?) AS running,
                    SUM(available_at > ? AND (locked_until IS NULL OR locked_until <= ?)) AS later,
                    MIN(CASE WHEN available_at > ? THEN available_at END) AS next_at,
                    MIN(CASE WHEN available_at <= ? THEN available_at END) AS oldest_at
             FROM {$this->tables->jobs()}" . ($only === null ? '' : " WHERE {$by} = ?") . " GROUP BY {$by} ORDER BY {$by}",
            [$moment, $moment, $moment, $moment, $moment, $moment, $moment, ...($only === null ? [] : [$only])],
        );
        $waiting = [];
        foreach ($rows as $row) {
            $waiting[\is_string($row['name']) ? $row['name'] : ''] = new WaitingJobs(
                self::int($row['due']),
                self::int($row['running']),
                self::int($row['later']),
                self::moment($row['next_at']),
                self::moment($row['oldest_at']),
            );
        }

        return $waiting;
    }

    /**
     * @param list<int> $ids
     */
    private function release(array $ids): void
    {
        $this->tables->connection()->executeStatement(
            "UPDATE {$this->tables->jobs()} SET locked_until = NULL, locked_by = NULL WHERE id IN (" . implode(', ', array_fill(0, \count($ids), '?')) . ')',
            $ids,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($decoded)) {
            return [];
        }
        $payload = [];
        foreach ($decoded as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }

    /** ISO 8601 in UTC, as the database keeps it. */
    public static function moment(mixed $value): ?string
    {
        return \is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))->format(\DATE_ATOM) : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
