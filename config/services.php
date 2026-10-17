<?php

declare(strict_types=1);

use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use OrillaCart\Geography\Domain\CountryRepository;
use OrillaCart\Geography\Infrastructure\Doctrine\DoctrineCountryRepository;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Doctrine\MigrationsFactory;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\WordPress\Logging\DebugLogLogger;
use OrillaCart\Platform\WordPress\WordPressIntegration;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use OrillaCart\Tax\Infrastructure\Doctrine\DoctrineTaxGroupRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionTaxSettingsRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Every class is a service unless it is data: entities, value objects and events (Domain), DTOs,
    // attributes, exceptions and route values.
    $services->load('OrillaCart\\', '../src/')
        ->exclude([
            '../src/*/Domain/',
            '../src/*/Application/Dto/',
            '../src/Platform/Kernel.php',
            '../src/Platform/PluginContext.php',
            '../src/Platform/DependencyInjection/',
            '../src/Platform/WordPress/Plugin.php',
            '../src/Platform/Doctrine/DatabaseConfig.php',
            '../src/Platform/Doctrine/{TablePrefixListener,TablePrefixMigrationFactory}.php',
            '../src/Platform/Doctrine/StrictMysqli/',
            '../src/Shared/Http/HttpException.php',
            '../src/Shared/Http/Routing/{Route,RouteDefinition}.php',
            '../src/Shared/Security/',
            '../src/Shared/WordPress/AsHook.php',
        ]);

    // Application services are each area's API: other areas, WordPress hooks and WP-CLI commands call them.
    $services->load('OrillaCart\\', '../src/*/Application/')
        ->exclude('../src/*/Application/Dto/')
        ->public();

    // Known only at runtime: set by the Kernel after the container is built.
    $services->set(PluginContext::class)->synthetic();

    // Filled from #[Route] and #[AsHook] attributes by RoutePass and HookPass.
    $services->set(RouteCollection::class)->args(['$routes' => []]);

    // Repositories: the domain declares the interface, infrastructure decides where data lives.
    $services->alias(SettingsRepository::class, OptionSettingsRepository::class);
    $services->alias(CountryRepository::class, DoctrineCountryRepository::class);
    $services->alias(TaxGroupRepository::class, DoctrineTaxGroupRepository::class);
    $services->alias(TaxSettingsRepository::class, OptionTaxSettingsRepository::class);

    // Database
    $services->set(DatabaseConfig::class)
        ->factory([DatabaseConfig::class, 'fromWordPress']);
    $services->set(EntityManagerInterface::class)
        ->factory([service(EntityManagerFactory::class), 'create']);
    $services->set(DependencyFactory::class)
        ->factory([service(MigrationsFactory::class), 'create']);

    // PSR-7 and PSR-17
    $services->set(Psr17Factory::class);
    $services->alias(ResponseFactoryInterface::class, Psr17Factory::class);
    $services->alias(ServerRequestFactoryInterface::class, Psr17Factory::class);
    $services->alias(StreamFactoryInterface::class, Psr17Factory::class);
    $services->alias(UriFactoryInterface::class, Psr17Factory::class);

    // Serialization and validation
    $services->set(Serializer::class)
        ->factory([service(SerializerFactory::class), 'create']);
    $services->alias(SerializerInterface::class, Serializer::class);
    $services->alias(NormalizerInterface::class, Serializer::class);
    $services->alias(DenormalizerInterface::class, Serializer::class);
    $services->set(NameConverterInterface::class)
        ->factory([service(SerializerFactory::class), 'nameConverter']);
    $services->set(ValidatorInterface::class)
        ->factory([service(ValidatorFactory::class), 'create']);

    // Events (PSR-14); listeners come from #[AsEventListener] and EventSubscriberInterface.
    $services->set('event_dispatcher', EventDispatcher::class);
    $services->alias(Psr\EventDispatcher\EventDispatcherInterface::class, 'event_dispatcher');

    // Logging (PSR-3)
    $services->alias(LoggerInterface::class, DebugLogLogger::class);

    // Storefront templates
    $services->set(Environment::class)
        ->factory([service(TwigFactory::class), 'create']);

    // Entry points used by the WordPress bootstrap and WP-CLI
    $services->set(WordPressIntegration::class)->public();
    $services->set(PluginLifecycle::class)->public();
    $services->set(DatabaseMigrator::class)->public();
};
