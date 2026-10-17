<?php

declare(strict_types=1);

namespace OrillaCart\Platform;

use Psr\Container\ContainerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainer;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Builds the service container: the class compiled during the release build when it exists,
 * otherwise compiled from config/services.php on each request (development).
 */
final class Kernel
{
    /** Written by the release build; returns the compiled container instance. */
    public const string COMPILED_CONTAINER_FILE = 'generated/container.php';

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
        $loader = new PhpFileLoader($builder, new FileLocator($pluginDir . '/config'));
        $loader->load('services.php');

        return $builder;
    }

    private function loadContainer(): SymfonyContainer
    {
        $compiled = $this->context->path(self::COMPILED_CONTAINER_FILE);

        if (is_file($compiled)) {
            $container = require $compiled;
            if (!$container instanceof SymfonyContainer) {
                throw new \LogicException($compiled . ' does not define a service container.');
            }

            return $container;
        }

        $builder = self::buildContainer($this->context->dir);
        $builder->compile();

        return $builder;
    }
}
