<?php

declare(strict_types=1);

use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Cart\Infrastructure\Doctrine\DoctrineCartRepository;
use OrillaCart\Catalog\Domain\AttributeSetRepository;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\ProductSearch;
use OrillaCart\Catalog\Infrastructure\Doctrine\DoctrineAttributeSetRepository;
use OrillaCart\Catalog\Infrastructure\Doctrine\DoctrineProductRepository;
use OrillaCart\Catalog\Infrastructure\WordPress\WordPressProductContent;
use OrillaCart\Catalog\Infrastructure\WordPress\WordPressProductSearch;
use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Domain\CustomerSettingsRepository;
use OrillaCart\Customers\Infrastructure\WordPress\OptionCustomerSettingsRepository;
use OrillaCart\Customers\Infrastructure\WordPress\WordPressAccounts;
use OrillaCart\Extension\Registry;
use OrillaCart\Geography\Domain\CountryRepository;
use OrillaCart\Geography\Infrastructure\Doctrine\DoctrineCountryRepository;
use OrillaCart\Inventory\Domain\StockLevelRepository;
use OrillaCart\Inventory\Domain\StockroomRepository;
use OrillaCart\Inventory\Infrastructure\Doctrine\DoctrineStockLevelRepository;
use OrillaCart\Inventory\Infrastructure\Doctrine\DoctrineStockroomRepository;
use OrillaCart\Jobs\Application\JobHandlers;
use OrillaCart\Jobs\Application\JobQueue;
use OrillaCart\Jobs\Application\WorkerRegistry;
use OrillaCart\Jobs\Infrastructure\Doctrine\DbalJobQueue;
use OrillaCart\Jobs\Infrastructure\LocatedJobHandlers;
use OrillaCart\Jobs\Infrastructure\WordPress\TableWorkerRegistry;
use OrillaCart\Notifications\Application\Mailer;
use OrillaCart\Notifications\Application\Site;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Notifications\Infrastructure\WordPress\OptionEmailSettingsRepository;
use OrillaCart\Notifications\Infrastructure\WordPress\WordPressMailer;
use OrillaCart\Notifications\Infrastructure\WordPress\WordPressSite;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Domain\DownloadGrantRepository;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Infrastructure\Doctrine\DoctrineDownloadGrantRepository;
use OrillaCart\Ordering\Infrastructure\Doctrine\DoctrineOrderRepository;
use OrillaCart\Payment\Application\Gateway\BankTransfer;
use OrillaCart\Payment\Application\Gateway\CashOnDelivery;
use OrillaCart\Payment\Application\Gateway\Cheque;
use OrillaCart\Payment\Application\PaymentUrls;
use OrillaCart\Payment\Application\PayPal\PayPalApi;
use OrillaCart\Payment\Application\PayPal\PayPalGateway;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Infrastructure\Doctrine\DoctrinePaymentMethodRepository;
use OrillaCart\Payment\Infrastructure\Doctrine\DoctrinePaymentRepository;
use OrillaCart\Payment\Infrastructure\PayPal\WordPressPayPalApi;
use OrillaCart\Payment\Infrastructure\WordPress\RestPaymentUrls;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\DoctrineLoadedEntities;
use OrillaCart\Platform\Doctrine\DoctrineTransactions;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Doctrine\MigrationsFactory;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\WordPress\AfterResponse\ShutdownWork;
use OrillaCart\Platform\WordPress\Extensions\WordPressExtensions;
use OrillaCart\Platform\WordPress\Files\LocalPrivateFiles;
use OrillaCart\Platform\WordPress\Files\WebServerFileDelivery;
use OrillaCart\Platform\WordPress\Logging\DebugLogLogger;
use OrillaCart\Platform\WordPress\WordPressIntegration;
use OrillaCart\Search\Application\CatalogSearch;
use OrillaCart\Search\Application\ProductTexts;
use OrillaCart\Search\Application\SearchIndex;
use OrillaCart\Search\Application\SearchServer;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchFilterRepository;
use OrillaCart\Search\Infrastructure\Doctrine\DoctrineSearchFilterRepository;
use OrillaCart\Search\Infrastructure\EngineCatalogSearch;
use OrillaCart\Search\Infrastructure\EngineSearchIndex;
use OrillaCart\Search\Infrastructure\OpenSearch\OpenSearchServer;
use OrillaCart\Search\Infrastructure\WordPress\OptionEngineSettingsRepository;
use OrillaCart\Search\Infrastructure\WordPress\WordPressProductTexts;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\Files\FileDelivery;
use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Lifecycle\AfterResponse;
use OrillaCart\Shared\Persistence\LoadedEntities;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shipping\Application\RateCache;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ShippingClassRepository;
use OrillaCart\Shipping\Infrastructure\Doctrine\DoctrineCarrierRepository;
use OrillaCart\Shipping\Infrastructure\Doctrine\DoctrineConsignmentRepository;
use OrillaCart\Shipping\Infrastructure\Doctrine\DoctrineShippingClassRepository;
use OrillaCart\Shipping\Infrastructure\WordPress\TransientRateCache;
use OrillaCart\Storefront\Domain\ShopPagesRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionShopPagesRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use OrillaCart\Tax\Infrastructure\Doctrine\DoctrineTaxGroupRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionTaxSettingsRepository;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
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
            '../src/Extension/',
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
            '../src/Search/Infrastructure/Doctrine/{FullTextQuery,SqlParams}.php',
        ]);

    // Application services are each area's API: other areas, WordPress hooks and WP-CLI commands call them.
    $services->load('OrillaCart\\', '../src/*/Application/')
        ->exclude('../src/*/Application/Dto/')
        ->public();

    // The shop's own payment gateways, in this order; add-ons register theirs after them (WordPressExtensions).
    $services->set(CashOnDelivery::class)->tag(WordPressExtensions::GATEWAY_TAG, ['priority' => 40]);
    $services->set(BankTransfer::class)->tag(WordPressExtensions::GATEWAY_TAG, ['priority' => 30]);
    $services->set(Cheque::class)->tag(WordPressExtensions::GATEWAY_TAG, ['priority' => 20]);
    $services->set(PayPalGateway::class)->tag(WordPressExtensions::GATEWAY_TAG, ['priority' => 10]);
    $services->alias(InstalledExtensions::class, WordPressExtensions::class);
    $services->alias(Registry::class, WordPressExtensions::class);
    $services->alias(PayPalApi::class, WordPressPayPalApi::class);
    $services->alias(PaymentUrls::class, RestPaymentUrls::class);

    // Known only at runtime: set by the Kernel after the container is built.
    $services->set(PluginContext::class)->synthetic();

    // Filled from #[Route] and #[AsHook] attributes by RoutePass and HookPass.
    $services->set(RouteCollection::class)->args(['$routes' => []]);

    // Repositories: the domain declares the interface, infrastructure decides where data lives.
    $services->alias(SettingsRepository::class, OptionSettingsRepository::class);
    $services->alias(CountryRepository::class, DoctrineCountryRepository::class);
    $services->alias(TaxGroupRepository::class, DoctrineTaxGroupRepository::class);
    $services->alias(TaxSettingsRepository::class, OptionTaxSettingsRepository::class);
    $services->alias(ProductRepository::class, DoctrineProductRepository::class);
    $services->alias(AttributeSetRepository::class, DoctrineAttributeSetRepository::class);
    $services->alias(ProductContent::class, WordPressProductContent::class);
    $services->alias(ProductSearch::class, WordPressProductSearch::class);
    $services->alias(StockroomRepository::class, DoctrineStockroomRepository::class);
    $services->alias(StockLevelRepository::class, DoctrineStockLevelRepository::class);
    $services->alias(CarrierRepository::class, DoctrineCarrierRepository::class);
    $services->alias(ShippingClassRepository::class, DoctrineShippingClassRepository::class);
    $services->alias(PaymentMethodRepository::class, DoctrinePaymentMethodRepository::class);
    $services->alias(PaymentRepository::class, DoctrinePaymentRepository::class);
    $services->alias(OrderRepository::class, DoctrineOrderRepository::class);
    $services->alias(CartRepository::class, DoctrineCartRepository::class);
    $services->alias(OrderLinks::class, StorefrontOrderLinks::class);
    $services->alias(ShopPagesRepository::class, OptionShopPagesRepository::class);
    $services->alias(DownloadGrantRepository::class, DoctrineDownloadGrantRepository::class);
    $services->alias(EmailSettingsRepository::class, OptionEmailSettingsRepository::class);
    $services->alias(Mailer::class, WordPressMailer::class);
    $services->alias(Site::class, WordPressSite::class);
    $services->alias(Accounts::class, WordPressAccounts::class);
    $services->alias(CustomerSettingsRepository::class, OptionCustomerSettingsRepository::class);
    $services->alias(SearchFilterRepository::class, DoctrineSearchFilterRepository::class);
    $services->alias(ProductTexts::class, WordPressProductTexts::class);
    $services->alias(RateCache::class, TransientRateCache::class);
    $services->alias(ConsignmentRepository::class, DoctrineConsignmentRepository::class);

    // The search engine the shop chose: its database (MySQL FULLTEXT) or OpenSearch.
    $services->alias(CatalogSearch::class, EngineCatalogSearch::class);
    $services->alias(SearchIndex::class, EngineSearchIndex::class);
    $services->alias(SearchServer::class, OpenSearchServer::class);
    $services->alias(EngineSettingsRepository::class, OptionEngineSettingsRepository::class);

    // Background jobs: one queue for every area's work, run by WP-Cron, the end of requests and workers.
    $services->alias(JobQueue::class, DbalJobQueue::class);
    $services->alias(JobHandlers::class, LocatedJobHandlers::class);
    $services->alias(WorkerRegistry::class, TableWorkerRegistry::class);

    $services->alias(Transactions::class, DoctrineTransactions::class);
    $services->alias(LoadedEntities::class, DoctrineLoadedEntities::class);
    $services->alias(AfterResponse::class, ShutdownWork::class);
    $services->alias(PrivateFiles::class, LocalPrivateFiles::class);
    $services->alias(FileDelivery::class, WebServerFileDelivery::class);

    // Time (PSR-20): use cases ask the clock, so tests can fix "now".
    $services->set(ClockInterface::class, NativeClock::class);

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
    $services->alias(UploadedFileFactoryInterface::class, Psr17Factory::class);

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
