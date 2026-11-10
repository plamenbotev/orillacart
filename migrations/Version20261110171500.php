<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * An extension enabled (or stopped by the shop) before the shop kept the files it was enabled with (PLAN 389)
 * gets them now (PLAN 432): its files as they are, when they are still the ones it was enabled with (the checksum
 * of its scripts says so). One whose files changed since is switched off, with the reason as its problem, and runs
 * again once a manager enables it. Written as Package::enable() writes it: [path, content] pairs in path order, a
 * JSON list.
 *
 * Runs again harmlessly: only enabled and stopped packages without the files are touched, and each statement says
 * so too.
 */
final class Version20261110171500 extends Migration
{
    public const string CHANGED = 'Its files changed since it was enabled. Enable it again to run them.';

    public function getDescription(): string
    {
        return 'The files of extensions enabled before the shop kept them';
    }

    public function up(Schema $schema): void
    {
        $packages = $this->connection->fetchAllAssociative('SELECT id, enabled_checksum FROM ' . $this->table('packages') . " WHERE state IN ('enabled', 'stopped') AND enabled_files IS NULL");
        foreach ($packages as $package) {
            $id = $package['id'];
            if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
                continue;
            }
            $id = (int) $id;
            $files = $this->connection->fetchAllAssociative('SELECT path, content FROM ' . $this->table('package_files') . ' WHERE package_id = ? ORDER BY path ASC', [$id]);
            $snapshot = self::snapshot($files);
            if (self::checksum($snapshot) === $package['enabled_checksum']) {
                $this->addSql("UPDATE {prefix}packages SET enabled_files = ? WHERE id = ? AND state IN ('enabled', 'stopped') AND enabled_files IS NULL", [json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $id]);
            } else {
                $this->addSql("UPDATE {prefix}packages SET state = 'disabled', last_problem = ? WHERE id = ? AND state IN ('enabled', 'stopped') AND enabled_files IS NULL", [self::CHANGED, $id]);
            }
        }
    }

    public function down(Schema $schema): void {}

    public function isTransactional(): bool
    {
        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows path and content, in path order
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function snapshot(array $rows): array
    {
        $files = [];
        foreach ($rows as $row) {
            if (\is_string($row['path'] ?? null) && \is_string($row['content'] ?? null)) {
                $files[] = [$row['path'], $row['content']];
            }
        }

        return $files;
    }

    /**
     * Package::checksum() of these files: the scripts joined in path order.
     *
     * @param list<array{0: string, 1: string}> $files
     */
    public static function checksum(array $files): string
    {
        $scripts = [];
        foreach ($files as [$path, $content]) {
            if (str_ends_with($path, '.ocs')) {
                $scripts[] = $content;
            }
        }

        return substr(hash('sha256', implode("\n", $scripts)), 0, 16);
    }
}
