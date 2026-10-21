<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Mysqli\Connection as MysqliConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * WordPress's own database connection, for Doctrine: one connection a request instead of two, so a transaction
 * of the shop's holds what WordPress writes in it (posts, meta, options) and both share the session's settings
 * (the SQL mode wpdb sets). Each call takes wpdb's connection as it is then, as wpdb connects again when the
 * server has dropped it (a long-running worker).
 */
final class WordPressConnection implements Connection
{
    private ?MysqliConnection $connection = null;
    private ?\mysqli $for = null;

    /** Whether WordPress has a connection to share. */
    public static function available(): bool
    {
        return self::mysqli() !== null;
    }

    public function prepare(string $sql): Statement
    {
        return $this->current()->prepare($sql);
    }

    public function query(string $sql): Result
    {
        return $this->current()->query($sql);
    }

    public function quote(string $value): string
    {
        return $this->current()->quote($value);
    }

    public function exec(string $sql): int|string
    {
        return $this->current()->exec($sql);
    }

    public function lastInsertId(): int|string
    {
        return $this->current()->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->current()->beginTransaction();
    }

    public function commit(): void
    {
        $this->current()->commit();
    }

    public function rollBack(): void
    {
        $this->current()->rollBack();
    }

    public function getServerVersion(): string
    {
        return $this->current()->getServerVersion();
    }

    public function getNativeConnection(): \mysqli
    {
        return $this->current()->getNativeConnection();
    }

    private function current(): MysqliConnection
    {
        $mysqli = self::mysqli() ?? throw new \LogicException('WordPress has no database connection to share.');
        if ($mysqli !== $this->for || $this->connection === null) {
            $this->for = $mysqli;
            $this->connection = new MysqliConnection($mysqli);
        }

        return $this->connection;
    }

    private static function mysqli(): ?\mysqli
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        // Protected, but wpdb lets it be read (wpdb::__get(), "for backward compatibility").
        $mysqli = $wpdb instanceof \wpdb ? $wpdb->__get('dbh') : null;

        return $mysqli instanceof \mysqli ? $mysqli : null;
    }
}
