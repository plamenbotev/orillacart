<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use OrillaCart\Platform\PluginContext;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Doctrine on its own connection, with WordPress' credentials, seeing only the plugin's tables.
 */
final readonly class EntityManagerFactory
{
    /** Where each business area keeps its entities, relative to the plugin directory. */
    public const string ENTITY_DIRECTORIES = 'src/*/Domain';

    public function __construct(
        private DatabaseConfig $database,
        private PluginContext $context,
    ) {}

    public function create(): EntityManagerInterface
    {
        $entityDirectories = glob($this->context->path(self::ENTITY_DIRECTORIES), GLOB_ONLYDIR);

        // An explicit cache: ORMSetup's own default probes Memcached and Redis on 127.0.0.1, wrong on shared hosts.
        $config = ORMSetup::createAttributeMetadataConfig(
            paths: $entityDirectories === false ? [] : $entityDirectories,
            isDevMode: \defined('WP_DEBUG') && WP_DEBUG,
            cache: new ArrayAdapter(),
        );
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection($this->database->connectionParams(), $this->dbalConfiguration());

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener($this->database->tablePrefix));

        return new EntityManager($connection, $config, $events);
    }

    private function dbalConfiguration(): DbalConfiguration
    {
        $prefix = $this->database->tablePrefix;

        $configuration = new DbalConfiguration();
        // Schema tools and migrations must never see, diff or drop WordPress' own tables.
        $configuration->setSchemaAssetsFilter(
            static fn(string|AbstractAsset $asset): bool => str_starts_with(
                \is_string($asset) ? $asset : $asset->getName(),
                $prefix,
            ),
        );

        return $configuration;
    }
}
