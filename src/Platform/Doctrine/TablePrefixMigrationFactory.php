<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\MigrationFactory;
use Psr\Log\LoggerInterface;

/**
 * Creates migrations as Doctrine does, and hands each the site's table prefix.
 */
final readonly class TablePrefixMigrationFactory implements MigrationFactory
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private string $tablePrefix,
    ) {}

    public function createVersion(string $migrationClassName): AbstractMigration
    {
        $migration = new $migrationClassName($this->connection, $this->logger);
        if (!$migration instanceof Migration) {
            throw new \LogicException(\sprintf('%s must extend %s.', $migrationClassName, Migration::class));
        }
        $migration->setTablePrefix($this->tablePrefix);

        return $migration;
    }
}
