<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Checkout;

use OrillaCart\Cart\Application\AbandonedCarts;
use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Infrastructure\Events\ForgetDeletedCustomers as ForgetDeletedCarts;
use OrillaCart\Catalog\Application\DownloadService;
use OrillaCart\Catalog\Application\PriceHistory;
use OrillaCart\Catalog\Application\PriceResolver;
use OrillaCart\Catalog\Application\PriceRules;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\Event\DownloadRemoved;
use OrillaCart\Catalog\Infrastructure\Events\ConvertMeasures as ConvertProductMeasures;
use OrillaCart\Catalog\Infrastructure\Events\ForgetDeletedShippingClass;
use OrillaCart\Checkout\Application\AddressChecks;
use OrillaCart\Checkout\Application\AddressRules;
use OrillaCart\Checkout\Application\CheckoutFields;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\ManualOrders;
use OrillaCart\Checkout\Application\OrderEditing;
use OrillaCart\Checkout\Application\OrderFees;
use OrillaCart\Checkout\Application\OrderPricing;
use OrillaCart\Checkout\Application\PayNow;
use OrillaCart\Checkout\Application\Reorder;
use OrillaCart\Coupons\Application\CouponService;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Coupons\Infrastructure\Events\ForgetErasedCustomers as ForgetErasedRedemptions;
use OrillaCart\Coupons\Infrastructure\Events\ReleaseCouponUses;
use OrillaCart\Customers\Application\CustomerGroups;
use OrillaCart\Customers\Application\CustomerNotes;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\PersonalData;
use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Customers\Domain\Event\CustomerErased;
use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Customers\Infrastructure\GroupsOfShoppers;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\GiftCards\Application\GiftCardPayments;
use OrillaCart\GiftCards\Application\GiftCardSales;
use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\GiftCards\Infrastructure\Events\GiftCardsWithOrders;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Invoicing\Application\InvoiceAttachments;
use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Invoicing\Infrastructure\Events\IssueInvoices;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Application\SyncSubscriber;
use OrillaCart\Notifications\Application\CartReminders;
use OrillaCart\Notifications\Application\EmailWriter;
use OrillaCart\Notifications\Application\OrderEmails;
use OrillaCart\Notifications\Application\SendCartReminder;
use OrillaCart\Notifications\Application\SendOrderEmails;
use OrillaCart\Notifications\Infrastructure\Events\EmailAboutOrders;
use OrillaCart\Ordering\Application\Downloads;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderDeleting;
use OrillaCart\Ordering\Domain\Event\OrderMarkingPaid;
use OrillaCart\Ordering\Domain\Event\OrderNeedsAttention;
use OrillaCart\Ordering\Domain\Event\OrderNoteAdded;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderReaddressing;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderRepricing;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Infrastructure\Events\FlagOrdersInQuestion;
use OrillaCart\Ordering\Infrastructure\Events\ForgetDeletedCustomers;
use OrillaCart\Ordering\Infrastructure\Events\ForgetRemovedDownloads;
use OrillaCart\Ordering\Infrastructure\Events\SettleOrdersByPayment;
use OrillaCart\Payment\Application\Gateway\BankTransfer;
use OrillaCart\Payment\Application\Gateway\CashOnDelivery;
use OrillaCart\Payment\Application\Gateway\Cheque;
use OrillaCart\Payment\Application\Gateways;
use OrillaCart\Payment\Application\GiveBackLatePayments;
use OrillaCart\Payment\Application\PaymentMethodService;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Payment\Application\Payments;
use OrillaCart\Payment\Application\PayPal\PayPalGateway;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Payment\Domain\Event\PaymentCancelled;
use OrillaCart\Payment\Domain\Event\PaymentCompleted;
use OrillaCart\Payment\Domain\Event\PaymentFailed;
use OrillaCart\Payment\Domain\Event\PaymentHeld;
use OrillaCart\Payment\Domain\Event\PaymentInQuestion;
use OrillaCart\Payment\Infrastructure\Events\OrdersWithPayments;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Twig\EditedTemplateLoader;
use OrillaCart\Platform\Twig\EmailTemplateEditing;
use OrillaCart\Platform\Twig\EmailTwig;
use OrillaCart\Platform\Twig\ShopExtension;
use OrillaCart\Platform\Twig\Strictness;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\Twig\TwigRenderer;
use OrillaCart\Returns\Application\Returns;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\SettingsChanged;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Security\Signatures;
use OrillaCart\Shipping\Application\CarrierService;
use OrillaCart\Shipping\Application\Consignments;
use OrillaCart\Shipping\Application\DeliveryDays;
use OrillaCart\Shipping\Application\ShipmentTracking;
use OrillaCart\Shipping\Application\ShippingClassService;
use OrillaCart\Shipping\Application\ShippingMethods;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Shipping\Application\TrackConsignments;
use OrillaCart\Shipping\Domain\Event\ConsignmentBooked;
use OrillaCart\Shipping\Domain\Event\OrderReadyForPickup;
use OrillaCart\Shipping\Domain\Event\ShippingClassDeleted;
use OrillaCart\Shipping\Infrastructure\Events\ConvertMeasures as ConvertRateMeasures;
use OrillaCart\Shipping\Infrastructure\Events\KeepShippedOrders;
use OrillaCart\Tax\Application\TaxCalculator;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tax\Application\VatNumbers;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Cart\FakeCartLinks;
use OrillaCart\Tests\Fixtures\Cart\InMemoryAbandonedCartRepository;
use OrillaCart\Tests\Fixtures\Cart\InMemoryCartRepository;
use OrillaCart\Tests\Fixtures\Catalog\CatalogIds;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryAttributeSetRepository;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryPriceHistory;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryPriceRuleRepository;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductContent;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductRepository;
use OrillaCart\Tests\Fixtures\Coupons\InMemoryCouponRepository;
use OrillaCart\Tests\Fixtures\Coupons\InMemoryRedemptionRepository;
use OrillaCart\Tests\Fixtures\Customers\InMemoryAccounts;
use OrillaCart\Tests\Fixtures\Customers\InMemoryCustomerGroupRepository;
use OrillaCart\Tests\Fixtures\Customers\InMemoryCustomerNotes;
use OrillaCart\Tests\Fixtures\Customers\InMemoryCustomerSettingsRepository;
use OrillaCart\Tests\Fixtures\Customers\InMemoryPendingAccounts;
use OrillaCart\Tests\Fixtures\Customers\RecordingAccountMail;
use OrillaCart\Tests\Fixtures\FakeLanguages;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\GiftCards\InMemoryGiftCards;
use OrillaCart\Tests\Fixtures\GiftCards\MemoryGiftCardSettings;
use OrillaCart\Tests\Fixtures\GiftCards\SentGiftCards;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockLevelRepository;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockMovements;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockroomRepository;
use OrillaCart\Tests\Fixtures\Invoicing\FakeInvoiceLinks;
use OrillaCart\Tests\Fixtures\Invoicing\FakeInvoicePdfs;
use OrillaCart\Tests\Fixtures\Invoicing\InMemoryInvoices;
use OrillaCart\Tests\Fixtures\Invoicing\MemoryInvoiceSettings;
use OrillaCart\Tests\Fixtures\Invoicing\SofiaCalendar;
use OrillaCart\Tests\Fixtures\Jobs\TestJobs;
use OrillaCart\Tests\Fixtures\Newsletter\InMemoryNewsletterSettings;
use OrillaCart\Tests\Fixtures\Newsletter\InMemorySubscribers;
use OrillaCart\Tests\Fixtures\Notifications\FakeSite;
use OrillaCart\Tests\Fixtures\Notifications\InMemoryEmailSettingsRepository;
use OrillaCart\Tests\Fixtures\Notifications\InMemoryEmailTemplates;
use OrillaCart\Tests\Fixtures\Notifications\InMemorySentEmails;
use OrillaCart\Tests\Fixtures\Notifications\SentMail;
use OrillaCart\Tests\Fixtures\Ordering\FakeOrderLinks;
use OrillaCart\Tests\Fixtures\Ordering\InMemoryDownloadGrantRepository;
use OrillaCart\Tests\Fixtures\Ordering\InMemoryOrderRepository;
use OrillaCart\Tests\Fixtures\Payment\FakePaymentUrls;
use OrillaCart\Tests\Fixtures\Payment\FakePayPalApi;
use OrillaCart\Tests\Fixtures\Payment\FixedExtensions;
use OrillaCart\Tests\Fixtures\Payment\InMemoryNotifiedEvents;
use OrillaCart\Tests\Fixtures\Payment\InMemoryPaymentMethodRepository;
use OrillaCart\Tests\Fixtures\Payment\InMemoryPaymentRepository;
use OrillaCart\Tests\Fixtures\Payment\InMemoryRefundRepository;
use OrillaCart\Tests\Fixtures\Returns\InMemoryReturnRepository;
use OrillaCart\Tests\Fixtures\Settings\ChosenCurrency;
use OrillaCart\Tests\Fixtures\Settings\InMemorySettingsRepository;
use OrillaCart\Tests\Fixtures\Settings\MemoryCurrencySettings;
use OrillaCart\Tests\Fixtures\Shared\FixedUserNames;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryCarrierRepository;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryConsignmentRepository;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryShippingClassRepository;
use OrillaCart\Tests\Fixtures\Shipping\MemoryRateCache;
use OrillaCart\Tests\Fixtures\Tax\FakeVatRegistry;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use OrillaCart\Tests\Fixtures\TemporaryPrivateFiles;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * A whole shop in memory, wired as the container wires it: catalog, stock, tax, shipping, payments, cart,
 * checkout and orders. Payment events reach the orders as they do in the plugin.
 */
final class TestShop
{
    public readonly MockClock $clock;
    public readonly InMemoryGiftCards $giftCardRecords;
    public readonly MemoryCurrencySettings $currencySettings;
    public readonly ChosenCurrency $chosenCurrency;
    public readonly Currencies $currencies;
    public readonly MemoryGiftCardSettings $giftCardSettings;
    public readonly SentGiftCards $giftCardMail;
    public readonly GiftCardService $giftCards;
    public readonly GiftCardPayments $giftCardPayments;
    public readonly DeliveryDays $deliveryDays;
    public readonly AddressChecks $addressChecks;
    public readonly Returns $returns;
    public readonly Reorder $reorder;
    public readonly InMemoryCustomerNotes $customerNoteRecords;
    public readonly CustomerNotes $customerNotes;
    public readonly Newsletter $newsletter;
    public readonly InMemorySubscribers $subscribers;
    public readonly InMemoryNewsletterSettings $newsletterSettings;
    public readonly ExtensionFailures $failures;
    public readonly InMemoryProductContent $content;
    public readonly InMemoryProductRepository $productRecords;
    public readonly ProductService $products;
    public readonly ShopCatalog $catalog;
    public readonly StockService $stock;
    public readonly InMemoryStockMovements $stockMovements;
    public readonly StockroomService $stockrooms;
    public readonly TaxGroupService $taxGroups;
    public readonly InMemoryTaxSettingsRepository $taxSettings;
    public readonly InMemorySettingsRepository $settings;
    public readonly CarrierService $carriers;
    public readonly SettingsService $settingsService;
    public readonly MemoryRateCache $rateCache;
    public readonly InMemoryConsignmentRepository $consignmentRecords;
    public readonly Consignments $consignments;
    public readonly ShippingClassService $shippingClasses;
    public readonly PaymentMethodService $paymentMethods;
    public readonly InMemoryPaymentRepository $paymentRecords;
    public readonly FakePayPalApi $payPalApi;
    public readonly PaymentOutcomes $outcomes;
    public readonly InMemoryPriceRuleRepository $priceRuleRecords;
    public readonly InMemoryCustomerGroupRepository $customerGroupRecords;
    public readonly PriceRules $priceRules;
    public readonly ProductQuotes $quotes;
    public readonly CustomerGroups $customerGroups;
    public readonly CouponService $coupons;
    public readonly Discounts $discounts;
    public readonly InMemoryCouponRepository $couponRecords;
    public readonly InMemoryRedemptionRepository $redemptions;
    public readonly Refunds $refunds;
    public readonly InMemoryRefundRepository $refundRecords;
    public readonly CartService $carts;
    public readonly InMemoryCartRepository $cartRecords;
    public readonly InMemoryAbandonedCartRepository $abandonedCartRecords;
    public readonly AbandonedCarts $abandonedCarts;
    public readonly CartReminders $cartReminders;
    public readonly InMemoryOrderRepository $orderRecords;
    public readonly OrderService $orders;
    public readonly CheckoutService $checkout;
    public readonly PayNow $payNow;
    public readonly PriceHistory $priceHistory;
    public readonly InMemoryPriceHistory $pricePoints;
    public readonly FakeVatRegistry $vies;
    public readonly TwigRenderer $templates;
    public readonly TwigRenderer $emailTemplates;
    public readonly InMemoryEmailTemplates $emailTemplateRecords;
    public readonly EmailTemplateEditing $templateEditing;
    public readonly Invoices $invoices;
    public readonly InMemoryInvoices $invoiceRecords;
    public readonly MemoryInvoiceSettings $invoiceSettings;
    public readonly FakeInvoicePdfs $invoicePdfs;
    public readonly OrderPricing $pricing;
    public readonly OrderEditing $editing;
    public readonly ManualOrders $manualOrders;
    public readonly ImmediateTransactions $transactions;
    public readonly MemoryLogger $logger;
    /** What add-ons registered: tests add price adjusters, cart rules, checkout fields and order actions. */
    public readonly FixedExtensions $extensions;
    public readonly EventDispatcher $events;
    public readonly SentMail $mail;
    public readonly InMemoryEmailSettingsRepository $emailSettings;
    public readonly EmailWriter $writer;
    public readonly OrderEmails $emails;
    public readonly TestJobs $jobs;
    public readonly InMemorySentEmails $sentEmails;
    public readonly InMemoryAccounts $accounts;
    public readonly InMemoryPendingAccounts $pendingAccounts;
    public readonly RecordingAccountMail $accountMail;
    public readonly PaymentOptions $paymentOptions;
    public readonly FakeLanguages $languages;
    public readonly ReceiptLinks $receipts;
    public readonly Signatures $signatures;
    public readonly InMemoryNotifiedEvents $notifiedEvents;
    public readonly FakePaymentUrls $paymentUrls;
    public readonly FakeSite $site;
    public readonly InMemoryCustomerSettingsRepository $customerSettings;
    public readonly CustomerService $customers;
    public readonly PersonalData $personalData;
    public readonly TemporaryPrivateFiles $files;
    public readonly DownloadService $downloadFiles;
    public readonly Downloads $downloads;

    /**
     * @param list<ShippingMethod> $shippingMethods as add-ons would register them
     */
    public function __construct(string $now = '2026-10-17 12:00:00 UTC', array $shippingMethods = [])
    {
        $this->clock = new MockClock($now);
        $this->jobs = new TestJobs($this->clock);
        $this->jobs->immediate = true;
        $this->transactions = new ImmediateTransactions();
        $this->events = new EventDispatcher();
        $this->logger = new MemoryLogger();
        $geography = new GeographyService(new InMemoryCountryRepository());
        $this->languages = new FakeLanguages();
        $this->settings = new InMemorySettingsRepository(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG'));
        $this->settingsService = $settings = new SettingsService($this->settings, $this->events, $geography);

        $taxGroupRecords = new InMemoryTaxGroupRepository();
        $this->taxSettings = new InMemoryTaxSettingsRepository(new TaxSettings(TaxBasis::ShippingAddress, null));
        $this->taxGroups = new TaxGroupService($taxGroupRecords, $this->taxSettings, $geography, $this->events);
        $taxes = new TaxCalculator($taxGroupRecords, $this->taxSettings, $settings, $geography);

        $carrierRecords = new InMemoryCarrierRepository();
        $classRecords = new InMemoryShippingClassRepository();
        $this->payPalApi = new FakePayPalApi();
        $this->extensions = $extensions = new FixedExtensions([new CashOnDelivery(), new BankTransfer(), new Cheque(), new PayPalGateway($this->payPalApi)], $shippingMethods);
        $shippingMethodList = new ShippingMethods($extensions);
        $this->rateCache = new MemoryRateCache();
        $this->carriers = new CarrierService($carrierRecords, $geography, $this->taxGroups, $classRecords, $shippingMethodList);
        $this->shippingClasses = new ShippingClassService($classRecords, $carrierRecords, $this->events);

        $ids = new CatalogIds();
        $this->productRecords = $productRecords = new InMemoryProductRepository($ids);
        $this->events->addListener(ShippingClassDeleted::class, new ForgetDeletedShippingClass($productRecords)(...));
        $this->content = new InMemoryProductContent();
        $this->products = new ProductService($productRecords, new InMemoryAttributeSetRepository($ids), $this->content, $this->taxGroups, $this->shippingClasses, $this->events);
        $this->events->addListener(SettingsChanged::class, new ConvertProductMeasures($this->products)(...));
        $this->events->addListener(SettingsChanged::class, new ConvertRateMeasures($this->carriers)(...));
        $stockroomRecords = new InMemoryStockroomRepository();
        $this->stockrooms = new StockroomService($stockroomRecords);
        $this->stock = new StockService(new InMemoryStockLevelRepository(), $stockroomRecords, $this->events, $this->stockMovements = new InMemoryStockMovements(), $this->clock);
        $this->priceRuleRecords = new InMemoryPriceRuleRepository();
        $this->customerGroupRecords = new InMemoryCustomerGroupRepository();
        $prices = new PriceResolver($this->priceRuleRecords, new GroupsOfShoppers($this->customerGroupRecords), $this->clock, $extensions, $settings, $this->logger, $this->failures = new ExtensionFailures());
        $this->priceRules = new PriceRules($this->priceRuleRecords, $this->events);
        $quotes = new ProductQuotes($productRecords, $this->content, $this->stock, $this->clock, $settings, $prices);
        $this->quotes = $quotes;
        $this->priceHistory = new PriceHistory($this->pricePoints = new InMemoryPriceHistory(), $this->clock);
        $this->catalog = new ShopCatalog($productRecords, $this->content, $this->stock, $this->clock, $settings, $prices, $quotes, $this->priceHistory);

        $methodRecords = new InMemoryPaymentMethodRepository();
        $this->paymentRecords = new InMemoryPaymentRepository($this->transactions);
        $gateways = new Gateways($extensions);
        $this->signatures = new Signatures('test secret');
        $this->paymentUrls = $urls = new FakePaymentUrls($this->signatures);
        $this->paymentMethods = new PaymentMethodService($methodRecords, $gateways, $settings, $urls);
        $payments = new Payments($this->paymentRecords, $methodRecords, $gateways, $urls, $this->clock, $this->logger);

        $this->cartRecords = new InMemoryCartRepository($this->transactions);
        $this->abandonedCartRecords = new InMemoryAbandonedCartRepository($this->cartRecords);
        $this->currencySettings = new MemoryCurrencySettings();
        $this->chosenCurrency = new ChosenCurrency();
        $this->currencies = new Currencies($this->currencySettings, $settings, $this->chosenCurrency);
        $this->carts = new CartService($this->cartRecords, $this->abandonedCartRecords, $quotes, $this->clock, $settings, $extensions, $this->events, $this->logger, $this->currencies);
        $this->abandonedCarts = new AbandonedCarts($this->cartRecords, $this->abandonedCartRecords, $this->carts, $settings, $this->clock);
        $this->orderRecords = new InMemoryOrderRepository($this->transactions);
        $this->receipts = new ReceiptLinks($this->signatures, $this->clock);
        $links = new FakeOrderLinks($this->receipts);
        $this->orders = new OrderService($this->orderRecords, $this->stock, $this->events, $this->clock, $links, $this->transactions, $this->receipts);
        $this->refundRecords = new InMemoryRefundRepository($this->transactions);
        $this->giftCardRecords = new InMemoryGiftCards($this->transactions);
        $this->giftCardSettings = new MemoryGiftCardSettings();
        $this->giftCardMail = new SentGiftCards();
        $this->giftCards = new GiftCardService($this->giftCardRecords, $this->giftCardSettings, $this->giftCardMail, $settings, $this->transactions, new FixedUserNames(), $this->clock);
        $this->refunds = new Refunds($this->refundRecords, $this->paymentRecords, $methodRecords, $gateways, $this->orders, $this->clock, $this->logger, $this->transactions, $this->giftCards, $this->events);
        $this->outcomes = new PaymentOutcomes($this->paymentRecords, $methodRecords, $gateways, $this->events, $this->clock, $this->logger, $this->refunds, $this->transactions, $links, $this->signatures, $this->notifiedEvents = new InMemoryNotifiedEvents());
        $this->files = new TemporaryPrivateFiles();
        $this->downloadFiles = new DownloadService($productRecords, $this->files, $this->events);
        $this->downloads = new Downloads(new InMemoryDownloadGrantRepository($this->orderRecords), $this->files, $links, $this->clock, $this->logger);
        $this->events->addListener(DownloadRemoved::class, new ForgetRemovedDownloads($this->downloads)(...));
        $this->accounts = new InMemoryAccounts();
        $this->customerSettings = new InMemoryCustomerSettingsRepository();
        $this->pendingAccounts = new InMemoryPendingAccounts();
        $this->accountMail = new RecordingAccountMail();
        $this->newsletter = new Newsletter($this->subscribers = new InMemorySubscribers(), $this->newsletterSettings = new InMemoryNewsletterSettings(), $extensions, $this->jobs->jobs, $this->clock);
        $this->jobs->with(new SyncSubscriber($this->newsletter, $this->logger));
        $this->customers = new CustomerService($this->accounts, $this->customerSettings, $this->orders, $this->customerGroupRecords, $this->events, $this->pendingAccounts, $this->accountMail, $this->accountMail, $this->clock, $settings, $this->customerNoteRecords = new InMemoryCustomerNotes(), $this->newsletter);
        $this->customerNotes = new CustomerNotes($this->customerNoteRecords, $this->accounts, new FixedUserNames(), $this->clock);
        $this->events->addListener(CustomerDeleted::class, new ForgetDeletedCustomers($this->orders)(...));
        $this->events->addListener(CustomerDeleted::class, new ForgetDeletedCarts($this->cartRecords, $this->abandonedCarts)(...));
        $this->events->addListener(CustomerErased::class, new ForgetDeletedCarts($this->cartRecords, $this->abandonedCarts)->erased(...));
        $this->events->addListener(PersonErased::class, new ForgetDeletedCarts($this->cartRecords, $this->abandonedCarts)->personErased(...));
        $this->personalData = new PersonalData($this->orders, $this->customers, $this->customerNotes, $this->newsletter, $this->accounts, $this->catalog);
        $this->customerGroups = new CustomerGroups($this->customerGroupRecords, $this->accounts);
        $shippingQuotes = new ShippingQuotes($carrierRecords, $classRecords, $shippingMethodList, $settings, $this->rateCache, $this->logger);
        $this->couponRecords = new InMemoryCouponRepository($this->transactions);
        $this->redemptions = new InMemoryRedemptionRepository();
        $this->events->addListener(OrderAnonymised::class, new ForgetErasedRedemptions($this->redemptions)->anonymised(...));
        $this->coupons = new CouponService($this->couponRecords);
        $this->discounts = new Discounts($this->couponRecords, $this->redemptions, $this->orders, $this->clock);
        $releasing = new ReleaseCouponUses($this->discounts);
        $this->events->addListener(OrderStatusChanged::class, $releasing->statusChanged(...));
        $this->events->addListener(OrderDeleted::class, $releasing->deleted(...));
        $this->giftCardPayments = new GiftCardPayments($this->giftCardRecords, $this->orders, $this->clock);
        $withGiftCards = new GiftCardsWithOrders($this->giftCardPayments, new GiftCardSales($this->giftCardRecords, $this->giftCardSettings, $this->orders, $this->giftCardMail, $this->transactions, $this->clock), $this->orders);
        $this->events->addListener(OrderStatusChanged::class, $withGiftCards->statusChanged(...));
        $this->events->addListener(OrderPaid::class, $withGiftCards->paid(...));
        $this->paymentOptions = $paymentOptions = new PaymentOptions($methodRecords, $gateways, $settings);
        $fees = new OrderFees($paymentOptions, $extensions, $this->logger, $this->failures);
        $this->pricing = new OrderPricing($quotes, $taxes, $shippingQuotes, $this->stock, $settings, $this->discounts, $fees, $this->currencies);
        $this->editing = new OrderEditing($this->orders, $this->pricing, $this->transactions, $shippingQuotes, $this->discounts);
        $this->manualOrders = new ManualOrders($this->pricing, $this->orders, $paymentOptions, new AddressRules($settings, $geography), $this->customers, $settings, $this->transactions, $this->discounts, $this->failures);
        $this->checkout = new CheckoutService(
            $this->carts,
            $this->pricing,
            $paymentOptions,
            $payments,
            $this->orders,
            $links,
            $settings,
            new AddressRules($settings, $geography),
            $this->transactions,
            $this->customers,
            $this->logger,
            $this->discounts,
            new CheckoutFields($extensions, $this->logger, $this->failures),
            new VatNumbers($this->vies = new FakeVatRegistry(), $this->taxSettings, $settings, $geography),
            $this->languages,
            $this->failures,
            $fees,
            $this->deliveryDays = new DeliveryDays($carrierRecords, $this->orders, new SofiaCalendar(), $this->clock),
            $this->addressChecks = new AddressChecks($extensions, $this->logger),
            $this->newsletter,
            $this->giftCardPayments,
            $this->currencies,
        );
        $this->reorder = new Reorder($this->orders, $this->carts);
        $this->returns = new Returns(new InMemoryReturnRepository(), $this->orders, $this->refunds, $settings, $this->clock);
        $this->payNow = new PayNow($this->orders, $paymentOptions, $payments, $links);
        $this->invoiceRecords = new InMemoryInvoices($this->transactions);
        $this->invoiceSettings = new MemoryInvoiceSettings();
        $this->invoices = new Invoices($this->invoiceRecords, $this->invoiceSettings, $this->orders, new FakeInvoiceLinks(), new SofiaCalendar(), $this->clock, $this->receipts);
        $issuing = new IssueInvoices($this->invoices);
        $this->events->addListener(OrderPaid::class, $issuing->paid(...));
        $this->events->addListener(OrderRefunded::class, $issuing->refunded(...));

        $this->mail = new SentMail();
        $this->emailSettings = new InMemoryEmailSettingsRepository();
        $context = new PluginContext(\dirname(__DIR__, 3) . '/orillacart.php', 'test');
        $shopExtension = new ShopExtension($settings, $geography, $taxes, $this->currencies);
        $twigFiles = new TwigFactory($context, $shopExtension);
        $this->templates = $templates = new TwigRenderer($twigFiles->create(), new SerializerFactory()->create(), $this->logger);
        $this->emailTemplateRecords = new InMemoryEmailTemplates();
        $edited = new EditedTemplateLoader($this->emailTemplateRecords);
        $strictness = new Strictness();
        $emailTwig = new EmailTwig($context, $twigFiles, $edited, $strictness, $shopExtension)->create();
        $this->emailTemplates = new TwigRenderer($emailTwig, new SerializerFactory()->create(), $this->logger, $strictness);
        $this->templateEditing = new EmailTemplateEditing($context, $twigFiles, $edited, $strictness, $emailTwig);
        $this->consignmentRecords = new InMemoryConsignmentRepository();
        $keepShipped = new KeepShippedOrders($this->consignmentRecords);
        $this->events->addListener(OrderDeleting::class, $keepShipped->deleting(...));
        $this->events->addListener(OrderReaddressing::class, $keepShipped->readdressing(...));
        $withPayments = new OrdersWithPayments($this->paymentRecords, $this->clock);
        $this->events->addListener(OrderDeleting::class, $withPayments->deleting(...));
        $this->events->addListener(OrderRepricing::class, $withPayments->repricing(...));
        $this->events->addListener(OrderMarkingPaid::class, $withPayments->markingPaid(...));
        $this->consignments = new Consignments($this->consignmentRecords, $carrierRecords, $shippingMethodList, $this->orders, $quotes, $settings, $this->clock, $this->logger, $this->events);
        $this->sentEmails = new InMemorySentEmails();
        $this->writer = new EmailWriter($this->emailTemplates, $this->emailSettings, $this->site = new FakeSite(), $this->languages);
        $this->emails = new OrderEmails($this->orders, $links, $this->emailSettings, $this->writer, $this->mail, $this->logger, new ShipmentTracking($this->consignmentRecords, $this->orders), $this->jobs->jobs, $this->invoices, $this->receipts, new InvoiceAttachments($this->invoices, $this->invoiceSettings, $this->invoicePdfs = new FakeInvoicePdfs(), $this->languages, $this->logger));
        $this->cartReminders = new CartReminders($this->abandonedCarts, new FakeCartLinks(), $this->coupons, $this->emailSettings, $settings, $this->writer, $this->mail, $this->jobs->jobs, $this->clock);
        $this->jobs->with(new SendCartReminder($this->cartReminders));
        $this->jobs->with(new SendOrderEmails($this->emails, $this->sentEmails, $this->clock, $this->logger), new TrackConsignments($this->consignments), new GiveBackLatePayments($this->refunds));
        $emailing = new EmailAboutOrders($this->emails);
        $this->events->addListener(OrderStatusChanged::class, $emailing->statusChanged(...));
        $this->events->addListener(OrderNoteAdded::class, $emailing->noteAdded(...));
        $this->events->addListener(OrderRefunded::class, $emailing->refunded(...));
        $this->events->addListener(OrderNeedsAttention::class, $emailing->needsAttention(...));
        $this->events->addListener(ConsignmentBooked::class, $emailing->consignmentBooked(...));
        $this->events->addListener(OrderReadyForPickup::class, $emailing->readyForPickup(...));
        $this->events->addListener(PaymentInQuestion::class, new FlagOrdersInQuestion($this->orders, $this->logger)->inQuestion(...));

        $settle = new SettleOrdersByPayment($this->orders, $this->jobs->jobs, $this->logger);
        $this->events->addListener(PaymentCompleted::class, $settle->completed(...));
        $this->events->addListener(PaymentHeld::class, $settle->held(...));
        $this->events->addListener(PaymentFailed::class, $settle->failed(...));
        $this->events->addListener(PaymentCancelled::class, $settle->cancelled(...));
    }

    /**
     * What a provider sends a customer back with: its reference, and the shop's signed payment id from the address
     * the payment started with.
     *
     * @return array<string, string>
     */
    public function returnQuery(string $reference, string $gateway = 'paypal'): array
    {
        $payment = $this->paymentRecords->findByReference($gateway, $reference);

        return ['token' => $reference] + ($payment === null ? [] : $this->paymentUrls->query($gateway, $payment->id()));
    }
}
