<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OrillaCart\Jobs\Application\Dto\FailedJobView;
use OrillaCart\Jobs\Application\FailedJobs;

/**
 * Failed jobs in the failed_jobs table. A retry moves them back in one transaction; a unique job whose key is
 * already pending again is merged into that one.
 */
final readonly class DbalFailedJobs implements FailedJobs
{
    private const string MOMENT = JobTables::MOMENT;

    public function __construct(private JobTables $tables) {}

    public function list(?string $type, int $limit, int $offset = 0): array
    {
        [$where, $params] = $type === null ? ['', []] : ['WHERE type = ?', [$type]];
        $rows = $this->tables->connection()->fetchAllAssociative(
            \sprintf('SELECT id, queue, type, unique_key, payload, error, attempts, failed_at FROM %s %s ORDER BY failed_at DESC, id DESC LIMIT %d OFFSET %d', $this->tables->failed(), $where, max(1, $limit), max(0, $offset)),
            $params,
        );

        return array_map(static function (array $row): FailedJobView {
            $payload = \is_string($row['payload']) ? json_decode($row['payload'], true) : null;
            $named = [];
            foreach (\is_array($payload) ? $payload : [] as $key => $value) {
                $named[(string) $key] = $value;
            }

            return new FailedJobView(
                (string) (is_numeric($row['id']) ? (int) $row['id'] : 0),
                \is_string($row['queue']) ? $row['queue'] : '',
                \is_string($row['type']) ? $row['type'] : '',
                \is_string($row['unique_key']) ? $row['unique_key'] : null,
                $named,
                \is_string($row['error']) ? $row['error'] : '',
                is_numeric($row['attempts']) ? (int) $row['attempts'] : 0,
                DbalJobQueue::moment($row['failed_at']) ?? '',
            );
        }, $rows);
    }

    public function count(?string $type = null): int
    {
        [$where, $params] = $type === null ? ['', []] : [' WHERE type = ?', [$type]];
        $count = $this->tables->connection()->fetchOne("SELECT COUNT(*) FROM {$this->tables->failed()}{$where}", $params);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function retry(?array $ids, \DateTimeImmutable $now): int
    {
        if ($ids === []) {
            return 0;
        }
        $jobs = $this->tables->jobs();
        $failed = $this->tables->failed();
        $moment = $now->format(self::MOMENT);

        return $this->tables->connection()->transactional(static function (Connection $connection) use ($ids, $jobs, $failed, $moment): int {
            [$where, $params] = self::which($ids);
            $connection->executeStatement(
                "INSERT INTO {$jobs} (queue, type, unique_key, payload, available_at, created_at, version, attempts)
                 SELECT queue, type, unique_key, payload, ?, ?, 1, 0 FROM {$failed}{$where}
                 ON DUPLICATE KEY UPDATE version = {$jobs}.version + 1, available_at = LEAST({$jobs}.available_at, VALUES(available_at)), attempts = 0, last_error = NULL",
                [$moment, $moment, ...$params],
            );

            return (int) $connection->executeStatement("DELETE FROM {$failed}{$where}", $params);
        });
    }

    public function forget(?array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        [$where, $params] = self::which($ids);

        return (int) $this->tables->connection()->executeStatement("DELETE FROM {$this->tables->failed()}{$where}", $params);
    }

    public function forgetMentioning(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }
        $like = '%' . addcslashes($text, '%_\\') . '%';

        return (int) $this->tables->connection()->executeStatement("DELETE FROM {$this->tables->failed()} WHERE payload LIKE ? OR error LIKE ?", [$like, $like]);
    }

    public function prune(\DateTimeImmutable $before, array $keep = []): void
    {
        if ($keep === []) {
            $this->tables->connection()->executeStatement("DELETE FROM {$this->tables->failed()} WHERE failed_at < ?", [$before->format(self::MOMENT)]);

            return;
        }
        $this->tables->connection()->executeStatement(
            "DELETE FROM {$this->tables->failed()} WHERE failed_at < ? AND type NOT IN (?)",
            [$before->format(self::MOMENT), $keep],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );
    }

    /**
     * @param list<string>|null $ids
     *
     * @return array{string, list<int>}
     */
    private static function which(?array $ids): array
    {
        if ($ids === null) {
            return ['', []];
        }

        return [' WHERE id IN (' . implode(', ', array_fill(0, \count($ids), '?')) . ')', array_map(intval(...), $ids)];
    }
}
