<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

/**
 * Keeps the plugin's tables at the latest migration, and removes them on uninstall.
 */
final readonly class DatabaseMigrator implements LifecycleListener, RemovesShopData
{
    public function __construct(
        private DependencyFactory $migrations,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return list<string> versions executed
     */
    public function migrate(): array
    {
        $this->migrations->getMetadataStorage()->ensureInitialized();

        $latest = $this->migrations->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $this->migrations->getMigrationPlanCalculator()->getPlanUntilVersion($latest);

        // MySQL commits DDL implicitly, so one transaction around all migrations would only pretend.
        $configuration = new MigratorConfiguration()->setAllOrNothing(false);
        $this->migrations->getMigrator()->migrate($plan, $configuration);

        $executed = [];
        foreach ($plan->getItems() as $item) {
            $executed[] = (string) $item->getVersion();
        }

        return $executed;
    }

    public function activate(): void
    {
        $this->migrate();
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->migrate();
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();

        // The connection's schema filter limits this list to the plugin's own prefixed tables.
        $tables = $schemaManager->listTableNames();

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $schemaManager->dropTable($table);
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
