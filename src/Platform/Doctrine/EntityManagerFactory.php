<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ToolEvents;
use OrillaCart\Platform\Doctrine\StrictMysqli\Middleware;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use OrillaCart\Shared\Security\Secrets;
use OrillaCart\Shared\Security\Signatures;
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
        private ?Secrets $secrets = null,
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
        // Properties are camelCase, columns snake_case: inEu is stored as in_eu.
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));

        if (!Type::hasType(SealedJsonType::NAME)) {
            Type::addType(SealedJsonType::NAME, SealedJsonType::class);
        }
        // The site's key; tools without WordPress (the schema, the build check) seal nothing they keep.
        SealedJsonType::useSecrets($this->secrets ?? new Secrets(\defined('ABSPATH') ? SiteSignatures::create() : new Signatures(random_bytes(32))));
        $connection = DriverManager::getConnection($this->database->connectionParams(), $this->dbalConfiguration());

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener($this->database->tablePrefix));
        $events->addEventListener(ToolEvents::postGenerateSchema, new ReferencesListener($this->database->tablePrefix));

        return new EntityManager($connection, $config, $events);
    }

    private function dbalConfiguration(): DbalConfiguration
    {
        $prefix = $this->database->tablePrefix;

        $configuration = new DbalConfiguration();
        $configuration->setMiddlewares([new Middleware()]);
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
