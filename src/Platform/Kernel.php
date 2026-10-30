<?php

declare(strict_types=1);

namespace OrillaCart\Platform;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Customers\Application\PersonalDataSource;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Ordering\Application\OrderLookup;
use OrillaCart\Ordering\Application\OrderMark;
use OrillaCart\Platform\DependencyInjection\HookPass;
use OrillaCart\Platform\DependencyInjection\RoutePass;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Shared\Http\Routing\ActionDispatcher;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\HoldsSealedValues;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Tax\Application\TaxGroupUse;
use OrillaCart\Translations\Application\TranslatableType;
use OrillaCart\Translations\Application\TranslationPackageSource;
use Psr\Container\ContainerInterface;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainer;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Builds the service container: the class compiled during the release build when it exists,
 * otherwise one compiled from config/services.php and cached in var/cache until a source file changes (development);
 * for a read-only plugin folder in wp-content/cache/orillacart/container.
 */
final class Kernel
{
    /** Written by the release build; returns the compiled container instance. */
    public const string COMPILED_CONTAINER_FILE = 'generated/container.php';

    /** Development cache, rebuilt when a file the container was built from changes. */
    public const string DEV_CONTAINER_FILE = 'var/cache/container.php';
    private const string DEV_CONTAINER_CLASS = 'DevContainer';
    private const string CONTAINER_NAMESPACE = 'OrillaCart\\Generated';

    private ?SymfonyContainer $container = null;

    public function __construct(public readonly PluginContext $context) {}

    public function container(): ContainerInterface
    {
        if ($this->container === null) {
            $this->container = $this->loadContainer();
            $this->container->set(PluginContext::class, $this->context);
        }

        return $this->container;
    }

    /**
     * The container definition, used by the build to dump the compiled class and in development.
     */
    public static function buildContainer(string $pluginDir): ContainerBuilder
    {
        $builder = new ContainerBuilder();

        $builder->registerAttributeForAutoconfiguration(
            Route::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(ActionDispatcher::ROUTE_TAG);
            },
        );
        $builder->registerAttributeForAutoconfiguration(
            AsHook::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(AsHook::TAG);
            },
        );
        $builder->registerAttributeForAutoconfiguration(
            AsEventListener::class,
            static function (ChildDefinition $definition, AsEventListener $listener, \Reflector $reflector): void {
                $attributes = array_filter(get_object_vars($listener), static fn(mixed $value): bool => $value !== null);
                if ($reflector instanceof \ReflectionMethod) {
                    $attributes['method'] = $reflector->getName();
                }
                $definition->addTag('kernel.event_listener', $attributes);
            },
        );
        $builder->registerForAutoconfiguration(EventSubscriberInterface::class)->addTag('kernel.event_subscriber');
        $builder->registerForAutoconfiguration(JobHandler::class)->addTag(JobHandler::TAG);
        $builder->registerForAutoconfiguration(OrderLookup::class)->addTag(OrderLookup::LOOKUP_TAG);
        $builder->registerForAutoconfiguration(OrderMark::class)->addTag(OrderMark::MARK_TAG);
        $builder->registerForAutoconfiguration(TaxGroupUse::class)->addTag(TaxGroupUse::TAG);
        $builder->registerForAutoconfiguration(TranslatableType::class)->addTag(TranslatableType::TAG);
        $builder->registerForAutoconfiguration(TranslationPackageSource::class)->addTag(TranslationPackageSource::TAG);
        $builder->registerForAutoconfiguration(PersonalDataSource::class)->addTag(PersonalDataSource::TAG);
        $builder->registerForAutoconfiguration(SetupStep::class)->addTag(SetupStep::TAG);
        $builder->registerForAutoconfiguration(ReadinessCheck::class)->addTag(ReadinessCheck::TAG);
        $builder->registerForAutoconfiguration(ToolSource::class)->addTag(ToolSource::TAG);
        $builder->registerForAutoconfiguration(CallDescription::class)->addTag(CallDescription::TAG);
        // Shared\Security is not scanned for services, so its interface's tag is given here.
        $builder->registerForAutoconfiguration(HoldsSealedValues::class)->addTag(HoldsSealedValues::SEALED_VALUES_TAG);

        $builder->addCompilerPass(new RoutePass());
        $builder->addCompilerPass(new HookPass());
        $builder->addCompilerPass(new RegisterListenersPass());

        $loader = new PhpFileLoader($builder, new FileLocator($pluginDir . '/config'));
        $loader->load('services.php');

        return $builder;
    }

    private static function dump(ContainerBuilder $builder, string $class): string
    {
        $code = new PhpDumper($builder)->dump([
            'namespace' => self::CONTAINER_NAMESPACE,
            'class' => $class,
        ]);
        if (!\is_string($code)) {
            throw new \LogicException('The container dumped into several files; expected one.');
        }

        return $code;
    }

    private function loadContainer(): SymfonyContainer
    {
        $compiled = $this->context->path(self::COMPILED_CONTAINER_FILE);
        if (is_file($compiled)) {
            return self::requireContainer($compiled);
        }

        $builder = null;
        foreach ($this->cacheFiles() as $file) {
            $cache = new ConfigCache($file, true);
            if ($cache->isFresh()) {
                return self::requireContainer($cache->getPath());
            }
            if ($builder === null) {
                $builder = self::buildContainer($this->context->dir);
                $builder->compile();
            }
            try {
                self::writeContainer($builder, \dirname($cache->getPath()), self::DEV_CONTAINER_CLASS, $cache);

                return self::requireContainer($cache->getPath());
            } catch (IOException) {
                // Not writable: the next place, else the container as built.
            }
        }

        // Nowhere to write: run on the builder, slower; its service locators do not know their classes (LocatedServices).
        return $builder ?? throw new \LogicException('The container has no place to be cached.');
    }

    /**
     * Where the development container is cached: the plugin's own var/cache, else, for a read-only plugin folder,
     * wp-content/cache/orillacart/container, a folder per plugin folder (CacheFolders removes it with the rest).
     *
     * @return list<string>
     */
    private function cacheFiles(): array
    {
        $files = [$this->context->path(self::DEV_CONTAINER_FILE)];
        if (\defined('WP_CONTENT_DIR')) {
            $files[] = WP_CONTENT_DIR . '/cache/orillacart/container/' . substr(hash('sha256', $this->context->dir), 0, 16) . '/container.php';
        }

        return $files;
    }

    /**
     * Writes the container class and a loader file that returns an instance; the loader can be required
     * any number of times in one process. With a ConfigCache the loader is written through it, so the
     * cache knows which source files to watch.
     */
    public static function writeContainer(ContainerBuilder $builder, string $dir, string $class, ?ConfigCache $cache = null): string
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($dir . '/' . $class . '.php', self::dump($builder, $class));

        $loader = "<?php\n\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/{$class}.php';\n\nreturn new \\"
            . self::CONTAINER_NAMESPACE . '\\' . $class . "();\n";
        if ($cache !== null) {
            $cache->write($loader, $builder->getResources());

            return $cache->getPath();
        }
        $filesystem->dumpFile($dir . '/container.php', $loader);

        return $dir . '/container.php';
    }

    private static function requireContainer(string $file): SymfonyContainer
    {
        $container = require $file;
        if (!$container instanceof SymfonyContainer) {
            throw new \LogicException($file . ' does not define a service container.');
        }

        return $container;
    }
}
