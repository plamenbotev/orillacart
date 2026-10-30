<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\Migrations\AbstractMigration;

/**
 * Base of the plugin's migrations. Their SQL names tables as {prefix}countries; the site's prefix
 * (wp_orillacart_ on most sites) replaces it when the migration runs, so one migration fits every site.
 *
 * Foreign key names are unique in the whole database, not only in their table, so the sites of a network (or two
 * installs sharing a database) cannot all use the names Doctrine generated. A site with another prefix gets names
 * of its own, made from its prefix and the generated name, the same in every migration that names the key.
 */
abstract class Migration extends AbstractMigration
{
    public const string PREFIX = '{prefix}';
    /** The prefix of the site migrations are generated on (npm run db:diff, on wp-env), whose names they hold. */
    public const string GENERATED_WITH = 'wp_orillacart_';

    private ?string $tablePrefix = null;

    /**
     * @internal set by TablePrefixMigrationFactory
     */
    public function setTablePrefix(string $tablePrefix): void
    {
        $this->tablePrefix = $tablePrefix;
    }

    /** A plugin table's name on this site, for reading data while a migration is planned. */
    protected function table(string $name): string
    {
        return ($this->tablePrefix ?? throw new \LogicException(static::class . ' runs without a table prefix.')) . $name;
    }

    /*
     * The guards below look at the schema as it is when the migration runs: MySQL commits each DDL statement by
     * itself, so a migration of several statements that failed halfway (the host's time limit, a lost connection)
     * is run again from the start, and what is there already must be skipped, not fail the retry.
     */

    /** Whether the plugin's table (its name without the prefix) is there. */
    protected function hasTable(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$this->table($table)]);
    }

    /** Whether the plugin's table has the column. */
    protected function hasColumn(string $table, string $column): bool
    {
        if (!$this->hasTable($table)) {
            return false;
        }
        foreach ($this->connection->createSchemaManager()->listTableColumns($this->table($table)) as $found) {
            if (strcasecmp($found->getName(), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Whether the plugin's table has the index, by name. */
    protected function hasIndex(string $table, string $index): bool
    {
        if (!$this->hasTable($table)) {
            return false;
        }
        foreach ($this->connection->createSchemaManager()->listTableIndexes($this->table($table)) as $found) {
            if (strcasecmp($found->getName(), $index) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $params
     * @param array<array-key, mixed> $types
     */
    protected function addSql(string $sql, array $params = [], array $types = []): void
    {
        $prefix = $this->tablePrefix ?? throw new \LogicException(static::class . ' runs without a table prefix.');
        parent::addSql(self::foreignKeys(str_replace(self::PREFIX, $prefix, $sql), $prefix), $params, $types);
    }

    /** The SQL with this site's foreign key names. */
    public static function foreignKeys(string $sql, string $prefix): string
    {
        if ($prefix === self::GENERATED_WITH) {
            return $sql;
        }

        return (string) preg_replace_callback(
            '/\bFK_[0-9A-F]{8,16}\b/',
            static fn(array $name): string => 'FK_' . strtoupper(substr(md5($prefix . $name[0]), 0, 16)),
            $sql,
        );
    }
}
