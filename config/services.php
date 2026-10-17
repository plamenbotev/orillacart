<?php

declare(strict_types=1);

use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Doctrine\MigrationsFactory;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Every class is a service unless it is data: entities and value objects (Domain), DTOs, migrations.
    $services->load('OrillaCart\\', '../src/')
        ->exclude([
            '../src/*/Domain/',
            '../src/*/Application/Dto/',
            '../src/Platform/Kernel.php',
            '../src/Platform/PluginContext.php',
            '../src/Platform/WordPress/Plugin.php',
            '../src/Platform/Doctrine/DatabaseConfig.php',
            '../src/Platform/Doctrine/TablePrefixListener.php',
        ]);

    // Known only at runtime: set by the Kernel after the container is built.
    $services->set(PluginContext::class)->synthetic();

    $services->set(DatabaseConfig::class)
        ->factory([DatabaseConfig::class, 'fromWordPress']);

    $services->set(EntityManagerInterface::class)
        ->factory([service(EntityManagerFactory::class), 'create']);

    $services->set(DependencyFactory::class)
        ->factory([service(MigrationsFactory::class), 'create']);

    $services->set(PluginLifecycle::class)->public();

    $services->set(DatabaseMigrator::class)->public();
};
