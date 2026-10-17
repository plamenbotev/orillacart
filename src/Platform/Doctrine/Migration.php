<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\Migrations\AbstractMigration;

/**
 * Base of the plugin's migrations. Their SQL names tables as {prefix}countries; the site's prefix
 * (wp_orillacart_ on most sites) replaces it when the migration runs, so one migration fits every site.
 */
abstract class Migration extends AbstractMigration
{
    public const string PREFIX = '{prefix}';

    private ?string $tablePrefix = null;

    /**
     * @internal set by TablePrefixMigrationFactory
     */
    public function setTablePrefix(string $tablePrefix): void
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * @param array<array-key, mixed> $params
     * @param array<array-key, mixed> $types
     */
    protected function addSql(string $sql, array $params = [], array $types = []): void
    {
        $prefix = $this->tablePrefix ?? throw new \LogicException(static::class . ' runs without a table prefix.');
        parent::addSql(str_replace(self::PREFIX, $prefix, $sql), $params, $types);
    }
}
