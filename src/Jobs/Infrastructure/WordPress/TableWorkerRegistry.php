<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\Dto\WorkerView;
use OrillaCart\Jobs\Application\WorkerRegistry;
use OrillaCart\Jobs\Infrastructure\Doctrine\JobTables;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

/**
 * Workers in the job_workers table, a row each, so many workers never write the same row; the restart request and
 * WP-Cron's last run in options, read straight from the database: WordPress caches options for the life of a
 * process, and a worker lives for hours.
 */
final readonly class TableWorkerRegistry implements WorkerRegistry, LifecycleListener, RemovesShopData
{
    public const string RESTART = 'orillacart_jobs_restart';
    public const string CRON = 'orillacart_jobs_cron';
    private const string MOMENT = JobTables::MOMENT;

    public function __construct(private JobTables $tables) {}

    public function heartbeat(string $worker, array $queues, \DateTimeImmutable $startedAt, \DateTimeImmutable $now, ?string $working, int $jobsDone, int $memory): void
    {
        $this->tables->connection()->executeStatement(
            "INSERT INTO {$this->tables->workers()} (name, queues, started_at, seen_at, working, jobs_done, memory) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE queues = VALUES(queues), started_at = VALUES(started_at), seen_at = VALUES(seen_at),
                 working = VALUES(working), jobs_done = VALUES(jobs_done), memory = VALUES(memory)",
            [mb_substr($worker, 0, 100), mb_substr(implode(',', $queues), 0, 255), $startedAt->format(self::MOMENT), $now->format(self::MOMENT), $working, $jobsDone, $memory],
        );
    }

    public function forget(string $worker): void
    {
        $this->tables->connection()->executeStatement("DELETE FROM {$this->tables->workers()} WHERE name = ?", [$worker]);
    }

    public function workers(\DateTimeImmutable $since): array
    {
        $rows = $this->tables->connection()->fetchAllAssociative(
            "SELECT name, queues, started_at, seen_at, working, jobs_done, memory FROM {$this->tables->workers()} WHERE seen_at >= ? ORDER BY name",
            [$since->format(self::MOMENT)],
        );

        return array_map(static fn(array $row): WorkerView => new WorkerView(
            self::string($row['name']),
            array_values(array_filter(explode(',', self::string($row['queues'])), static fn(string $queue): bool => $queue !== '')),
            self::moment($row['started_at']),
            self::moment($row['seen_at']),
            \is_string($row['working']) ? $row['working'] : null,
            is_numeric($row['jobs_done']) ? (int) $row['jobs_done'] : 0,
            is_numeric($row['memory']) ? (int) $row['memory'] : 0,
        ), $rows);
    }

    public function prune(\DateTimeImmutable $before): void
    {
        $this->tables->connection()->executeStatement("DELETE FROM {$this->tables->workers()} WHERE seen_at < ?", [$before->format(self::MOMENT)]);
    }

    public function restartRequestedAt(): ?\DateTimeImmutable
    {
        return self::read(self::RESTART);
    }

    public function requestRestart(\DateTimeImmutable $at): void
    {
        update_option(self::RESTART, (string) $at->getTimestamp(), false);
    }

    public function cronRanAt(): ?\DateTimeImmutable
    {
        return self::read(self::CRON);
    }

    public function cronRan(\DateTimeImmutable $at): void
    {
        update_option(self::CRON, (string) $at->getTimestamp(), false);
    }

    public function cronDisabled(): bool
    {
        return \defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::RESTART);
        delete_option(self::CRON);
    }

    private static function read(string $option): ?\DateTimeImmutable
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return null;
        }
        $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option));

        return is_numeric($value) ? new \DateTimeImmutable('@' . (int) $value) : null;
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /** ISO 8601 in UTC, as the database keeps it. */
    private static function moment(mixed $value): string
    {
        return \is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))->format(\DATE_ATOM) : '';
    }
}
