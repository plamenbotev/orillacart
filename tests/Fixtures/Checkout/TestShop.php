<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Checkout;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Catalog\Application\DownloadService;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Domain\Event\DownloadRemoved;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\OrderEditing;
use OrillaCart\Checkout\Application\OrderPricing;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Notifications\Application\OrderEmails;
use OrillaCart\Notifications\Infrastructure\Events\EmailAboutOrders;
use OrillaCart\Ordering\Application\Downloads;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\Event\OrderNoteAdded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Infrastructure\Events\ForgetRemovedDownloads;
use OrillaCart\Ordering\Infrastructure\Events\SettleOrdersByPayment;
use OrillaCart\Payment\Application\Gateway\BankTransfer;
use OrillaCart\Payment\Application\Gateway\CashOnDelivery;
use OrillaCart\Payment\Application\Gateway\Cheque;
use OrillaCart\Payment\Application\Gateways;
use OrillaCart\Payment\Application\PaymentMethodService;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Payment\Application\Payments;
use OrillaCart\Payment\Application\PayPal\PayPalGateway;
use OrillaCart\Payment\Application\PayPal\PayPalPayments;
use OrillaCart\Payment\Domain\Event\PaymentCancelled;
use OrillaCart\Payment\Domain\Event\PaymentCompleted;
use OrillaCart\Payment\Domain\Event\PaymentFailed;
use OrillaCart\Payment\Domain\Event\PaymentHeld;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Twig\ShopExtension;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\Twig\TwigRenderer;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shipping\Application\CarrierService;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Tax\Application\TaxCalculator;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Cart\InMemoryCartRepository;
use OrillaCart\Tests\Fixtures\Catalog\CatalogIds;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryAttributeSetRepository;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductContent;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductRepository;
use OrillaCart\Tests\Fixtures\Customers\InMemoryAccounts;
use OrillaCart\Tests\Fixtures\Customers\InMemoryCustomerSettingsRepository;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockLevelRepository;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockroomRepository;
use OrillaCart\Tests\Fixtures\Notifications\FakeSite;
use OrillaCart\Tests\Fixtures\Notifications\InMemoryEmailSettingsRepository;
use OrillaCart\Tests\Fixtures\Notifications\SentMail;
use OrillaCart\Tests\Fixtures\Ordering\FakeOrderLinks;
use OrillaCart\Tests\Fixtures\Ordering\InMemoryDownloadGrantRepository;
use OrillaCart\Tests\Fixtures\Ordering\InMemoryOrderRepository;
use OrillaCart\Tests\Fixtures\Payment\FakePaymentUrls;
use OrillaCart\Tests\Fixtures\Payment\FakePayPalApi;
use OrillaCart\Tests\Fixtures\Payment\InMemoryPaymentMethodRepository;
use OrillaCart\Tests\Fixtures\Payment\InMemoryPaymentRepository;
use OrillaCart\Tests\Fixtures\Settings\InMemorySettingsRepository;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryCarrierRepository;
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
    public readonly InMemoryProductContent $content;
    public readonly ProductService $products;
    public readonly StockService $stock;
    public readonly StockroomService $stockrooms;
    public readonly TaxGroupService $taxGroups;
    public readonly InMemoryTaxSettingsRepository $taxSettings;
    public readonly InMemorySettingsRepository $settings;
    public readonly CarrierService $carriers;
    public readonly PaymentMethodService $paymentMethods;
    public readonly InMemoryPaymentRepository $paymentRecords;
    public readonly FakePayPalApi $payPalApi;
    public readonly PayPalPayments $payPal;
    public readonly CartService $carts;
    public readonly InMemoryCartRepository $cartRecords;
    public readonly InMemoryOrderRepository $orderRecords;
    public readonly OrderService $orders;
    public readonly CheckoutService $checkout;
    public readonly OrderPricing $pricing;
    public readonly OrderEditing $editing;
    public readonly ImmediateTransactions $transactions;
    public readonly MemoryLogger $logger;
    public readonly EventDispatcher $events;
    public readonly SentMail $mail;
    public readonly InMemoryEmailSettingsRepository $emailSettings;
    public readonly OrderEmails $emails;
    public readonly InMemoryAccounts $accounts;
    public readonly InMemoryCustomerSettingsRepository $customerSettings;
    public readonly CustomerService $customers;
    public readonly TemporaryPrivateFiles $files;
    public readonly DownloadService $downloadFiles;
    public readonly Downloads $downloads;

    public function __construct(string $now = '2026-10-17 12:00:00 UTC')
    {
        $this->clock = new MockClock($now);
        $this->events = new EventDispatcher();
        $this->logger = new MemoryLogger();
        $geography = new GeographyService(new InMemoryCountryRepository());
        $this->settings = new InMemorySettingsRepository(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG'));
        $settings = new SettingsService($this->settings, $this->events, $geography);

        $taxGroupRecords = new InMemoryTaxGroupRepository();
        $this->taxSettings = new InMemoryTaxSettingsRepository(new TaxSettings(TaxBasis::ShippingAddress, null));
        $this->taxGroups = new TaxGroupService($taxGroupRecords, $this->taxSettings, $geography, $this->events);
        $taxes = new TaxCalculator($taxGroupRecords, $this->taxSettings, $settings, $geography);

        $ids = new CatalogIds();
        $productRecords = new InMemoryProductRepository($ids);
        $this->content = new InMemoryProductContent();
        $this->products = new ProductService($productRecords, new InMemoryAttributeSetRepository($ids), $this->content, $this->taxGroups, $this->events);
        $stockroomRecords = new InMemoryStockroomRepository();
        $this->stockrooms = new StockroomService($stockroomRecords);
        $this->stock = new StockService(new InMemoryStockLevelRepository(), $stockroomRecords, $this->events);
        $quotes = new ProductQuotes($productRecords, $this->content, $this->stock, $this->clock);

        $carrierRecords = new InMemoryCarrierRepository();
        $this->carriers = new CarrierService($carrierRecords, $geography, $this->taxGroups);

        $methodRecords = new InMemoryPaymentMethodRepository();
        $this->paymentRecords = new InMemoryPaymentRepository();
        $this->payPalApi = new FakePayPalApi();
        $gateways = new Gateways([new CashOnDelivery(), new BankTransfer(), new Cheque(), new PayPalGateway($this->payPalApi)]);
        $urls = new FakePaymentUrls();
        $this->paymentMethods = new PaymentMethodService($methodRecords, $gateways, $settings, $urls);
        $payments = new Payments($this->paymentRecords, $methodRecords, $gateways, $urls, $this->clock);
        $this->payPal = new PayPalPayments($this->paymentRecords, $methodRecords, $this->payPalApi, $this->events, $this->clock, $this->logger);

        $this->cartRecords = new InMemoryCartRepository();
        $this->carts = new CartService($this->cartRecords, $quotes, $this->clock);
        $this->orderRecords = new InMemoryOrderRepository();
        $this->orders = new OrderService($this->orderRecords, $this->stock, $this->events, $this->clock, new FakeOrderLinks());
        $this->transactions = new ImmediateTransactions();
        $this->files = new TemporaryPrivateFiles();
        $this->downloadFiles = new DownloadService($productRecords, $this->files, $this->events);
        $this->downloads = new Downloads(new InMemoryDownloadGrantRepository($this->orderRecords), $this->files, new FakeOrderLinks(), $this->clock, $this->logger);
        $this->events->addListener(DownloadRemoved::class, new ForgetRemovedDownloads($this->downloads)(...));
        $this->accounts = new InMemoryAccounts();
        $this->customerSettings = new InMemoryCustomerSettingsRepository();
        $this->customers = new CustomerService($this->accounts, $this->customerSettings, $this->orders);
        $this->pricing = new OrderPricing($quotes, $taxes, new ShippingQuotes($carrierRecords), $this->stock, $settings);
        $this->editing = new OrderEditing($this->orders, $this->pricing, $this->transactions);
        $this->checkout = new CheckoutService(
            $this->carts,
            $this->pricing,
            new PaymentOptions($methodRecords, $gateways),
            $payments,
            $this->orders,
            new FakeOrderLinks(),
            $settings,
            $geography,
            $this->transactions,
            $this->customers,
            $this->logger,
        );

        $this->mail = new SentMail();
        $this->emailSettings = new InMemoryEmailSettingsRepository();
        $context = new PluginContext(\dirname(__DIR__, 3) . '/orillacart.php', 'test');
        $templates = new TwigRenderer(new TwigFactory($context, new ShopExtension($settings))->create(), new SerializerFactory()->create());
        $this->emails = new OrderEmails($this->orders, new FakeOrderLinks(), $this->emailSettings, $templates, $this->mail, new FakeSite(), $this->logger);
        $emailing = new EmailAboutOrders($this->emails);
        $this->events->addListener(OrderStatusChanged::class, $emailing->statusChanged(...));
        $this->events->addListener(OrderNoteAdded::class, $emailing->noteAdded(...));

        $settle = new SettleOrdersByPayment($this->orders, $this->logger);
        $this->events->addListener(PaymentCompleted::class, $settle->completed(...));
        $this->events->addListener(PaymentHeld::class, $settle->held(...));
        $this->events->addListener(PaymentFailed::class, $settle->failed(...));
        $this->events->addListener(PaymentCancelled::class, $settle->cancelled(...));
    }
}
