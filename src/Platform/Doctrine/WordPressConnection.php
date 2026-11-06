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
 *
 * On a network whose tables are spread over several servers by a database drop-in (HyperDB, LudicrousDB), wpdb
 * holds one handle per server and `dbh` is only the one its last query used. The drop-in is then asked which
 * server holds this site's shop tables, as it would be for a write to them (design 54), and that handle is kept
 * for as long as the drop-in keeps it open: a transaction stays on one server, and so does a read after a write.
 */
final class WordPressConnection implements Connection
{
    /** A shop table of the site, as the drop-in parses it out of a statement to choose the server; never run. */
    private const string ROUTING_TABLE = 'orillacart_orders';

    private ?MysqliConnection $connection = null;
    private ?\mysqli $for = null;

    /** Whether WordPress has a connection to share. */
    public static function available(): bool
    {
        return new self()->mysqli() !== null;
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
        $mysqli = $this->mysqli() ?? throw new \LogicException('WordPress has no database connection to share.');
        if ($mysqli !== $this->for || $this->connection === null) {
            $this->for = $mysqli;
            $this->connection = new MysqliConnection($mysqli);
        }

        return $this->connection;
    }

    /**
     * The handle for this site's tables: the one pinned while the drop-in keeps it open, the drop-in's answer
     * otherwise, or wpdb's single handle when there is no drop-in.
     */
    private function mysqli(): ?\mysqli
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!$wpdb instanceof \wpdb) {
            return null;
        }
        // Only the drop-ins keep several handles; core wpdb has one.
        if (!property_exists($wpdb, 'dbhs')) {
            // Protected, but wpdb lets it be read (wpdb::__get(), "for backward compatibility").
            $mysqli = $wpdb->__get('dbh');

            return $mysqli instanceof \mysqli ? $mysqli : null;
        }
        /** @var mixed $open */
        $open = $wpdb->dbhs;
        if ($this->for !== null && \is_array($open) && \in_array($this->for, $open, true)) {
            return $this->for;
        }

        return self::askDropIn($wpdb);
    }

    /**
     * Asks the drop-in for the server that holds the site's shop tables, with a write-shaped statement it only
     * parses: a write names the dataset's primary, never a replica.
     */
    private static function askDropIn(\wpdb $wpdb): \mysqli
    {
        $statement = \sprintf('UPDATE %s%s SET id = id WHERE 0', $wpdb->prefix, self::ROUTING_TABLE);
        $first = new \ReflectionMethod($wpdb, 'db_connect')->getParameters()[0] ?? null;
        // HyperDB: db_connect($query). LudicrousDB keeps core's first parameter: db_connect($allow_bail, $query).
        $handle = self::handle(
            $first?->getName() === 'query'
                ? $wpdb->db_connect($statement) // @phpstan-ignore argument.type
                : $wpdb->db_connect(true, $statement), // @phpstan-ignore arguments.count
        );

        return $handle ?? throw new \LogicException(\sprintf('The database drop-in (%s) named no server for the tables %s*.', $wpdb::class, $wpdb->prefix . DatabaseConfig::TABLE_PREFIX_SUFFIX));
    }

    /** What a drop-in answered: a handle, or false when it could not connect. */
    private static function handle(mixed $answer): ?\mysqli
    {
        return $answer instanceof \mysqli ? $answer : null;
    }
}
