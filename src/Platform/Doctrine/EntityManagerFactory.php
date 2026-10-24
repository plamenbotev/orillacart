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
use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\Doctrine\StrictMysqli\Middleware;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use OrillaCart\Shared\Security\Secrets;
use OrillaCart\Shared\Security\Signatures;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

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

        $devMode = \defined('WP_DEBUG') && WP_DEBUG;
        // An explicit cache: ORMSetup's own default probes Memcached and Redis on 127.0.0.1, wrong on shared hosts.
        $config = ORMSetup::createAttributeMetadataConfig(
            paths: $entityDirectories === false ? [] : $entityDirectories,
            isDevMode: $devMode,
            cache: $devMode ? new ArrayAdapter() : $this->metadataCache(),
        );
        $config->enableNativeLazyObjects(true);
        // Properties are camelCase, columns snake_case: inEu is stored as in_eu.
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));

        if (!Type::hasType(SealedJsonType::NAME)) {
            Type::addType(SealedJsonType::NAME, SealedJsonType::class);
        }
        if (!Type::hasType(SealedStringType::NAME)) {
            Type::addType(SealedStringType::NAME, SealedStringType::class);
        }
        // The site's key; tools without WordPress (the schema, the build check) seal nothing they keep.
        $secrets = $this->secrets ?? new Secrets(\defined('ABSPATH') ? SiteSignatures::create() : new Signatures(random_bytes(32)));
        SealedJsonType::useSecrets($secrets);
        SealedStringType::useSecrets($secrets);
        $connection = DriverManager::getConnection($this->database->connectionParams(), $this->dbalConfiguration());

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener($this->database->tablePrefix));
        $events->addEventListener(ToolEvents::postGenerateSchema, new ReferencesListener($this->database->tablePrefix));

        return new EntityManager($connection, $config, $events);
    }

    /**
     * The mapping, read from the entities' attributes once per version and kept as PHP files, which OPcache
     * keeps in memory: every request would otherwise read every entity class. One set per table prefix, as each
     * site of a network maps the entities to its own tables. In memory without a writable cache folder.
     */
    private function metadataCache(): CacheItemPoolInterface
    {
        $dir = CacheFolders::path('doctrine', $this->context->version);
        if ($dir === null) {
            return new ArrayAdapter();
        }

        return new PhpFilesAdapter('meta.' . substr(hash('sha256', $this->database->tablePrefix), 0, 12), 0, $dir);
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
