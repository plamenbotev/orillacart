<?php

declare(strict_types=1);

use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use OrillaCart\Audit\Domain\ChangeRecordRepository;
use OrillaCart\Audit\Infrastructure\Doctrine\DoctrineChangeRecordRepository;
use OrillaCart\Cart\Application\CartLinks;
use OrillaCart\Cart\Domain\AbandonedCartRepository;
use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Cart\Infrastructure\Doctrine\DoctrineAbandonedCartRepository;
use OrillaCart\Cart\Infrastructure\Doctrine\DoctrineCartRepository;
use OrillaCart\Catalog\Application\ShopperGroups;
use OrillaCart\Catalog\Application\ShopperPricing;
use OrillaCart\Catalog\Domain\AttributeSetRepository;
use OrillaCart\Catalog\Domain\PriceHistoryRepository;
use OrillaCart\Catalog\Domain\PriceRuleRepository;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\ProductSearch;
use OrillaCart\Catalog\Infrastructure\Doctrine\DoctrineAttributeSetRepository;
use OrillaCart\Catalog\Infrastructure\Doctrine\DoctrinePriceHistoryRepository;
use OrillaCart\Catalog\Infrastructure\Doctrine\DoctrinePriceRuleRepository;
use OrillaCart\Catalog\Infrastructure\Doctrine\DoctrineProductRepository;
use OrillaCart\Catalog\Infrastructure\WordPress\WordPressProductContent;
use OrillaCart\Catalog\Infrastructure\WordPress\WordPressProductPosts;
use OrillaCart\Catalog\Infrastructure\WordPress\WordPressProductSearch;
use OrillaCart\Coupons\Domain\CouponRepository;
use OrillaCart\Coupons\Domain\RedemptionRepository;
use OrillaCart\Coupons\Infrastructure\Doctrine\DoctrineCouponRepository;
use OrillaCart\Coupons\Infrastructure\Doctrine\DoctrineRedemptionRepository;
use OrillaCart\Customers\Application\AccountLinks;
use OrillaCart\Customers\Application\AccountMail;
use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Domain\CustomerGroupRepository;
use OrillaCart\Customers\Domain\CustomerNoteRepository;
use OrillaCart\Customers\Domain\CustomerSettingsRepository;
use OrillaCart\Customers\Domain\PendingAccountRepository;
use OrillaCart\Customers\Infrastructure\Doctrine\DoctrineCustomerGroupRepository;
use OrillaCart\Customers\Infrastructure\Doctrine\DoctrineCustomerNoteRepository;
use OrillaCart\Customers\Infrastructure\Doctrine\DoctrinePendingAccountRepository;
use OrillaCart\Customers\Infrastructure\GroupsOfShoppers;
use OrillaCart\Customers\Infrastructure\WordPress\OptionCustomerSettingsRepository;
use OrillaCart\Customers\Infrastructure\WordPress\WordPressAccounts;
use OrillaCart\Exchange\Application\ColumnNames;
use OrillaCart\Exchange\Application\ProductPosts;
use OrillaCart\Exchange\Domain\ExportChoices;
use OrillaCart\Exchange\Domain\ProductImportRepository;
use OrillaCart\Exchange\Infrastructure\Doctrine\DoctrineProductImports;
use OrillaCart\Exchange\Infrastructure\WordPress\OptionExportChoices;
use OrillaCart\Exchange\Infrastructure\WordPress\TranslatedColumnNames;
use OrillaCart\Extension\Registry;
use OrillaCart\Forms\Domain\FormRepository;
use OrillaCart\Forms\Domain\FormUploadRepository;
use OrillaCart\Forms\Domain\UploadInspector;
use OrillaCart\Forms\Infrastructure\Doctrine\DoctrineFormRepository;
use OrillaCart\Forms\Infrastructure\Doctrine\DoctrineFormUploadRepository;
use OrillaCart\Forms\Infrastructure\SafeUploadInspector;
use OrillaCart\Geography\Domain\CountryRepository;
use OrillaCart\Geography\Infrastructure\Doctrine\DoctrineCountryRepository;
use OrillaCart\GiftCards\Application\GiftCardMail;
use OrillaCart\GiftCards\Domain\GiftCardRepository;
use OrillaCart\GiftCards\Domain\GiftCardSettingsRepository;
use OrillaCart\GiftCards\Infrastructure\Doctrine\DoctrineGiftCardRepository;
use OrillaCart\GiftCards\Infrastructure\WordPress\OptionGiftCardSettingsRepository;
use OrillaCart\Integrations\Application\ViewNormalizer;
use OrillaCart\Integrations\Application\WebhookSender;
use OrillaCart\Integrations\Application\WebhookTopics;
use OrillaCart\Integrations\Domain\ApiKeyRepository;
use OrillaCart\Integrations\Domain\WebhookRepository;
use OrillaCart\Integrations\Infrastructure\Doctrine\DoctrineApiKeyRepository;
use OrillaCart\Integrations\Infrastructure\Doctrine\DoctrineWebhookRepository;
use OrillaCart\Integrations\Infrastructure\SerializerViewNormalizer;
use OrillaCart\Integrations\Infrastructure\WordPress\WordPressWebhookSender;
use OrillaCart\Integrations\Infrastructure\WordPress\WordPressWebhookTopics;
use OrillaCart\Inventory\Domain\StockLevelRepository;
use OrillaCart\Inventory\Domain\StockMovementRepository;
use OrillaCart\Inventory\Domain\StockroomRepository;
use OrillaCart\Inventory\Infrastructure\Doctrine\DoctrineStockLevelRepository;
use OrillaCart\Inventory\Infrastructure\Doctrine\DoctrineStockMovements;
use OrillaCart\Inventory\Infrastructure\Doctrine\DoctrineStockroomRepository;
use OrillaCart\Invoicing\Application\InvoiceLinks;
use OrillaCart\Invoicing\Domain\InvoiceRepository;
use OrillaCart\Invoicing\Domain\InvoiceSettingsRepository;
use OrillaCart\Invoicing\Infrastructure\Doctrine\DoctrineInvoiceRepository;
use OrillaCart\Invoicing\Infrastructure\WordPress\OptionInvoiceSettingsRepository;
use OrillaCart\Invoicing\Infrastructure\WordPress\WordPressInvoiceLinks;
use OrillaCart\Jobs\Application\JobContext;
use OrillaCart\Jobs\Application\JobHandlers;
use OrillaCart\Jobs\Application\JobQueue;
use OrillaCart\Jobs\Application\WorkerRegistry;
use OrillaCart\Jobs\Infrastructure\Doctrine\DbalJobQueue;
use OrillaCart\Jobs\Infrastructure\LocatedJobHandlers;
use OrillaCart\Jobs\Infrastructure\WordPress\TableWorkerRegistry;
use OrillaCart\Jobs\Infrastructure\WordPress\WordPressJobContext;
use OrillaCart\Newsletter\Domain\NewsletterSettingsRepository;
use OrillaCart\Newsletter\Domain\SubscriberRepository;
use OrillaCart\Newsletter\Infrastructure\Doctrine\DoctrineSubscriberRepository;
use OrillaCart\Newsletter\Infrastructure\WordPress\OptionNewsletterSettingsRepository;
use OrillaCart\Notifications\Application\AccountEmails;
use OrillaCart\Notifications\Application\EmailWriter;
use OrillaCart\Notifications\Application\ExemptionEmails;
use OrillaCart\Notifications\Application\GiftCardEmails;
use OrillaCart\Notifications\Application\Mailer;
use OrillaCart\Notifications\Application\Site;
use OrillaCart\Notifications\Domain\AlertedStock;
use OrillaCart\Notifications\Domain\BackInStockRequests;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Notifications\Domain\EmailTemplateRepository;
use OrillaCart\Notifications\Domain\SentEmails;
use OrillaCart\Notifications\Infrastructure\Doctrine\DoctrineBackInStockRequests;
use OrillaCart\Notifications\Infrastructure\Doctrine\DoctrineEmailTemplates;
use OrillaCart\Notifications\Infrastructure\Doctrine\DoctrineSentEmails;
use OrillaCart\Notifications\Infrastructure\WordPress\OptionAlertedStock;
use OrillaCart\Notifications\Infrastructure\WordPress\OptionEmailSettingsRepository;
use OrillaCart\Notifications\Infrastructure\WordPress\WordPressMailer;
use OrillaCart\Notifications\Infrastructure\WordPress\WordPressSite;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\OrderLookups;
use OrillaCart\Ordering\Domain\DownloadGrantRepository;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Infrastructure\Doctrine\DoctrineDownloadGrantRepository;
use OrillaCart\Ordering\Infrastructure\Doctrine\DoctrineOrderRepository;
use OrillaCart\Ordering\Infrastructure\TaggedOrderLookups;
use OrillaCart\Payment\Application\Gateway\BankTransfer;
use OrillaCart\Payment\Application\Gateway\CashOnDelivery;
use OrillaCart\Payment\Application\Gateway\Cheque;
use OrillaCart\Payment\Application\PaymentUrls;
use OrillaCart\Payment\Application\PayPal\PayPalApi;
use OrillaCart\Payment\Application\PayPal\PayPalGateway;
use OrillaCart\Payment\Domain\NotifiedEvents;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Domain\RefundRepository;
use OrillaCart\Payment\Infrastructure\Doctrine\DoctrineNotifiedEvents;
use OrillaCart\Payment\Infrastructure\Doctrine\DoctrinePaymentMethodRepository;
use OrillaCart\Payment\Infrastructure\Doctrine\DoctrinePaymentRepository;
use OrillaCart\Payment\Infrastructure\Doctrine\DoctrineRefundRepository;
use OrillaCart\Payment\Infrastructure\PayPal\WordPressPayPalApi;
use OrillaCart\Payment\Infrastructure\WordPress\RestPaymentUrls;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\DoctrineLoadedEntities;
use OrillaCart\Platform\Doctrine\DoctrineTransactions;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Doctrine\MigrationsFactory;
use OrillaCart\Platform\Health\HealthStatusReport;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Sample\SampleProducts;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Platform\Settings\OptionSettingsArchive;
use OrillaCart\Platform\Twig\EmailTemplateEditing;
use OrillaCart\Platform\Twig\EmailTwig;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\Twig\TwigRenderer;
use OrillaCart\Platform\WordPress\AfterResponse\ShutdownWork;
use OrillaCart\Platform\WordPress\Extensions\WordPressExtensions;
use OrillaCart\Platform\WordPress\Files\LocalPrivateFiles;
use OrillaCart\Platform\WordPress\Files\WebServerFileDelivery;
use OrillaCart\Platform\WordPress\Logging\DebugLogLogger;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use OrillaCart\Platform\WordPress\WordPressIntegration;
use OrillaCart\Platform\WordPress\WordPressLanguages;
use OrillaCart\Platform\WordPress\WordPressUserNames;
use OrillaCart\Reports\Application\ReportQueries;
use OrillaCart\Reports\Application\ReportStore;
use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Reports\Infrastructure\Doctrine\DbalReportQueries;
use OrillaCart\Reports\Infrastructure\Doctrine\DbalReportStore;
use OrillaCart\Reports\Infrastructure\WordPress\SiteCalendar;
use OrillaCart\Returns\Domain\ReturnRepository;
use OrillaCart\Returns\Infrastructure\Doctrine\DoctrineReturnRepository;
use OrillaCart\Reviews\Domain\ReviewSettingsRepository;
use OrillaCart\Reviews\Domain\ReviewStore;
use OrillaCart\Reviews\Infrastructure\WordPress\CommentReviewStore;
use OrillaCart\Reviews\Infrastructure\WordPress\OptionReviewSettingsRepository;
use OrillaCart\Search\Application\CatalogSearch;
use OrillaCart\Search\Application\ProductTexts;
use OrillaCart\Search\Application\SearchIndex;
use OrillaCart\Search\Application\SearchServer;
use OrillaCart\Search\Domain\CategoryFiltersRepository;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchFilterRepository;
use OrillaCart\Search\Infrastructure\Doctrine\DoctrineCategoryFiltersRepository;
use OrillaCart\Search\Infrastructure\Doctrine\DoctrineSearchFilterRepository;
use OrillaCart\Search\Infrastructure\EngineCatalogSearch;
use OrillaCart\Search\Infrastructure\EngineSearchIndex;
use OrillaCart\Search\Infrastructure\OpenSearch\OpenSearchServer;
use OrillaCart\Search\Infrastructure\WordPress\OptionEngineSettingsRepository;
use OrillaCart\Search\Infrastructure\WordPress\WordPressProductTexts;
use OrillaCart\Settings\Application\CurrencyChoice;
use OrillaCart\Settings\Application\SampleData;
use OrillaCart\Settings\Application\SettingsArchive;
use OrillaCart\Settings\Application\SetupState;
use OrillaCart\Settings\Application\StatusReport;
use OrillaCart\Settings\Domain\CurrencySettingsRepository;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionCurrencySettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSetupState;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\Files\FileDelivery;
use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Lifecycle\AfterResponse;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\Persistence\LoadedEntities;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shared\Security\Secrets;
use OrillaCart\Shared\Security\Signatures;
use OrillaCart\Shared\Users\UserNames;
use OrillaCart\Shared\View\EditedTemplates;
use OrillaCart\Shared\View\TemplateEditing;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shipping\Application\RateCache;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ShippingClassRepository;
use OrillaCart\Shipping\Infrastructure\Doctrine\DoctrineCarrierRepository;
use OrillaCart\Shipping\Infrastructure\Doctrine\DoctrineConsignmentRepository;
use OrillaCart\Shipping\Infrastructure\Doctrine\DoctrineShippingClassRepository;
use OrillaCart\Shipping\Infrastructure\WordPress\TransientRateCache;
use OrillaCart\Storefront\Application\ProductCardsHtml;
use OrillaCart\Storefront\Domain\ShopPagesRepository;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\CartFromReminder;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\ShopperCurrency;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionShopPagesRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionStorefrontSettingsRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\RenderedProductCards;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontAccountLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;
use OrillaCart\Tax\Application\ExemptionMail;
use OrillaCart\Tax\Application\TaxQuoteCache;
use OrillaCart\Tax\Application\VatRegistry;
use OrillaCart\Tax\Domain\ImportRegistrationsRepository;
use OrillaCart\Tax\Domain\NexusThresholdsRepository;
use OrillaCart\Tax\Domain\TaxExemptionRepository;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use OrillaCart\Tax\Infrastructure\Doctrine\DoctrineTaxExemptionRepository;
use OrillaCart\Tax\Infrastructure\Doctrine\DoctrineTaxGroupRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionImportRegistrationsRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionNexusThresholdsRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionTaxSettingsRepository;
use OrillaCart\Tax\Infrastructure\WordPress\TransientTaxQuoteCache;
use OrillaCart\Tax\Infrastructure\WordPress\ViesVatRegistry;
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
            '../src/Exchange/Application/{CsvFile,JsonFile}.php',
        ]);

    // Application services are each area's API: other areas, WordPress hooks and WP-CLI commands call them.
    $services->load('OrillaCart\\', '../src/*/Application/')
        ->exclude(['../src/*/Application/Dto/', '../src/Exchange/Application/{CsvFile,JsonFile}.php'])
        ->public();
    // Made per shopper by PriceResolver, not a service.
    $services->remove(ShopperPricing::class);

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
    $services->alias(NexusThresholdsRepository::class, OptionNexusThresholdsRepository::class);
    $services->alias(ImportRegistrationsRepository::class, OptionImportRegistrationsRepository::class);
    $services->alias(TaxQuoteCache::class, TransientTaxQuoteCache::class);
    $services->alias(TaxExemptionRepository::class, DoctrineTaxExemptionRepository::class);
    $services->alias(ProductRepository::class, DoctrineProductRepository::class);
    $services->alias(AttributeSetRepository::class, DoctrineAttributeSetRepository::class);
    $services->alias(FormRepository::class, DoctrineFormRepository::class);
    $services->alias(FormUploadRepository::class, DoctrineFormUploadRepository::class);
    $services->alias(UploadInspector::class, SafeUploadInspector::class);
    $services->alias(ProductContent::class, WordPressProductContent::class);
    $services->alias(ProductSearch::class, WordPressProductSearch::class);
    $services->alias(ReportStore::class, DbalReportStore::class);
    $services->alias(ReportQueries::class, DbalReportQueries::class);
    $services->alias(ShopCalendar::class, SiteCalendar::class);
    $services->alias(StockroomRepository::class, DoctrineStockroomRepository::class);
    $services->alias(StockLevelRepository::class, DoctrineStockLevelRepository::class);
    $services->alias(StockMovementRepository::class, DoctrineStockMovements::class);
    $services->alias(UserNames::class, WordPressUserNames::class);
    $services->alias(BackInStockRequests::class, DoctrineBackInStockRequests::class);
    $services->alias(CarrierRepository::class, DoctrineCarrierRepository::class);
    $services->alias(ShippingClassRepository::class, DoctrineShippingClassRepository::class);
    $services->alias(PaymentMethodRepository::class, DoctrinePaymentMethodRepository::class);
    $services->alias(PaymentRepository::class, DoctrinePaymentRepository::class);
    $services->alias(RefundRepository::class, DoctrineRefundRepository::class);
    $services->alias(NotifiedEvents::class, DoctrineNotifiedEvents::class);
    $services->alias(OrderRepository::class, DoctrineOrderRepository::class);
    $services->alias(CartRepository::class, DoctrineCartRepository::class);
    $services->alias(OrderLinks::class, StorefrontOrderLinks::class);
    $services->alias(ShopPagesRepository::class, OptionShopPagesRepository::class);
    $services->alias(DownloadGrantRepository::class, DoctrineDownloadGrantRepository::class);
    $services->alias(EmailSettingsRepository::class, OptionEmailSettingsRepository::class);
    $services->alias(SentEmails::class, DoctrineSentEmails::class);
    $services->alias(AlertedStock::class, OptionAlertedStock::class);
    $services->alias(ExportChoices::class, OptionExportChoices::class);
    $services->alias(ColumnNames::class, TranslatedColumnNames::class);
    $services->alias(ProductPosts::class, WordPressProductPosts::class);
    $services->alias(CartLinks::class, CartFromReminder::class);
    $services->alias(AbandonedCartRepository::class, DoctrineAbandonedCartRepository::class);
    $services->alias(ProductImportRepository::class, DoctrineProductImports::class);
    $services->alias(EmailTemplateRepository::class, DoctrineEmailTemplates::class);
    $services->alias(EditedTemplates::class, DoctrineEmailTemplates::class);
    $services->alias(VatRegistry::class, ViesVatRegistry::class);
    $services->alias(PriceHistoryRepository::class, DoctrinePriceHistoryRepository::class);
    $services->alias(InvoiceRepository::class, DoctrineInvoiceRepository::class);
    $services->alias(InvoiceSettingsRepository::class, OptionInvoiceSettingsRepository::class);
    $services->alias(InvoiceLinks::class, WordPressInvoiceLinks::class);
    $services->alias(Mailer::class, WordPressMailer::class);
    $services->alias(Site::class, WordPressSite::class);
    $services->alias(Accounts::class, WordPressAccounts::class);
    $services->alias(CustomerSettingsRepository::class, OptionCustomerSettingsRepository::class);
    $services->alias(SearchFilterRepository::class, DoctrineSearchFilterRepository::class);
    $services->alias(CategoryFiltersRepository::class, DoctrineCategoryFiltersRepository::class);
    $services->alias(ProductTexts::class, WordPressProductTexts::class);
    $services->alias(RateCache::class, TransientRateCache::class);
    $services->alias(ConsignmentRepository::class, DoctrineConsignmentRepository::class);
    $services->alias(ReturnRepository::class, DoctrineReturnRepository::class);
    $services->alias(CouponRepository::class, DoctrineCouponRepository::class);
    $services->alias(CustomerGroupRepository::class, DoctrineCustomerGroupRepository::class);
    $services->alias(CustomerNoteRepository::class, DoctrineCustomerNoteRepository::class);
    $services->alias(SubscriberRepository::class, DoctrineSubscriberRepository::class);
    $services->alias(NewsletterSettingsRepository::class, OptionNewsletterSettingsRepository::class);
    $services->alias(ReviewSettingsRepository::class, OptionReviewSettingsRepository::class);
    $services->alias(ReviewStore::class, CommentReviewStore::class);
    $services->alias(StorefrontSettingsRepository::class, OptionStorefrontSettingsRepository::class);
    $services->alias(ProductCardsHtml::class, RenderedProductCards::class);
    $services->alias(StatusReport::class, HealthStatusReport::class);
    $services->alias(SettingsArchive::class, OptionSettingsArchive::class);
    $services->alias(SampleData::class, SampleProducts::class);
    $services->alias(SetupState::class, OptionSetupState::class);
    $services->alias(ChangeRecordRepository::class, DoctrineChangeRecordRepository::class);
    $services->alias(ApiKeyRepository::class, DoctrineApiKeyRepository::class);
    $services->alias(WebhookRepository::class, DoctrineWebhookRepository::class);
    $services->alias(WebhookSender::class, WordPressWebhookSender::class);
    $services->alias(WebhookTopics::class, WordPressWebhookTopics::class);
    $services->alias(ViewNormalizer::class, SerializerViewNormalizer::class);
    $services->alias(PendingAccountRepository::class, DoctrinePendingAccountRepository::class);
    $services->alias(AccountMail::class, AccountEmails::class);
    $services->alias(ExemptionMail::class, ExemptionEmails::class);
    $services->alias(GiftCardRepository::class, DoctrineGiftCardRepository::class);
    $services->alias(CurrencySettingsRepository::class, OptionCurrencySettingsRepository::class);
    $services->alias(CurrencyChoice::class, ShopperCurrency::class);
    $services->alias(GiftCardSettingsRepository::class, OptionGiftCardSettingsRepository::class);
    $services->alias(GiftCardMail::class, GiftCardEmails::class);
    $services->alias(AccountLinks::class, StorefrontAccountLinks::class);
    $services->alias(PriceRuleRepository::class, DoctrinePriceRuleRepository::class);
    $services->alias(ShopperGroups::class, GroupsOfShoppers::class);
    $services->alias(RedemptionRepository::class, DoctrineRedemptionRepository::class);

    // The search engine the shop chose: its database (MySQL FULLTEXT) or OpenSearch.
    $services->alias(CatalogSearch::class, EngineCatalogSearch::class);
    $services->alias(SearchIndex::class, EngineSearchIndex::class);
    $services->alias(SearchServer::class, OpenSearchServer::class);
    $services->alias(EngineSettingsRepository::class, OptionEngineSettingsRepository::class);

    // Background jobs: one queue for every area's work, run by WP-Cron, the end of requests and workers.
    $services->alias(JobQueue::class, DbalJobQueue::class);
    $services->alias(JobHandlers::class, LocatedJobHandlers::class);
    $services->alias(OrderLookups::class, TaggedOrderLookups::class);
    $services->alias(WorkerRegistry::class, TableWorkerRegistry::class);

    $services->alias(Transactions::class, DoctrineTransactions::class);
    $services->alias(LoadedEntities::class, DoctrineLoadedEntities::class);
    $services->alias(AfterResponse::class, ShutdownWork::class);
    $services->set(Signatures::class)->factory([SiteSignatures::class, 'create']);
    $services->set(Secrets::class)->args([service(Signatures::class)]);
    $services->alias(Languages::class, WordPressLanguages::class);
    $services->alias(JobContext::class, WordPressJobContext::class);
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
    // Email templates: the shop's edits before the files, always sandboxed
    $services->set('orillacart.email_twig', Environment::class)
        ->factory([service(EmailTwig::class), 'create']);
    $services->set('orillacart.email_templates', TwigRenderer::class)
        ->arg('$twig', service('orillacart.email_twig'));
    $services->get(EmailWriter::class)->arg('$templates', service('orillacart.email_templates'));
    $services->get(EmailTemplateEditing::class)->arg('$twig', service('orillacart.email_twig'));
    $services->alias(TemplateEditing::class, EmailTemplateEditing::class);
    $services->alias(TemplateRenderer::class, TwigRenderer::class);

    // Entry points used by the WordPress bootstrap and WP-CLI
    $services->set(WordPressIntegration::class)->public();
    $services->set(PluginLifecycle::class)->public();
    $services->set(DatabaseMigrator::class)->public();
};
