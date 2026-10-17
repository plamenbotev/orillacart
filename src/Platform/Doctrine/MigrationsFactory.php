<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\MigrationFactory;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Platform\PluginContext;

final readonly class MigrationsFactory
{
    public const string MIGRATIONS_NAMESPACE = 'OrillaCart\\Migrations';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DatabaseConfig $database,
        private PluginContext $context,
    ) {}

    public function create(): DependencyFactory
    {
        $factory = DependencyFactory::fromEntityManager(
            new ConfigurationArray([
                'migrations_paths' => [self::MIGRATIONS_NAMESPACE => $this->context->path('migrations')],
                'table_storage' => ['table_name' => $this->database->tablePrefix . 'migrations'],
                'all_or_nothing' => false,
                'transactional' => false,
                'check_database_platform' => true,
            ]),
            new ExistingEntityManager($this->entityManager),
        );
        $factory->setService(MigrationFactory::class, new TablePrefixMigrationFactory(
            $this->entityManager->getConnection(),
            $factory->getLogger(),
            $this->database->tablePrefix,
        ));

        return $factory;
    }
}
