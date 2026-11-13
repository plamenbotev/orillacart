<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Checkout\Application\AddressChecks;
use OrillaCart\Checkout\Application\CheckoutPrefills;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\EmailConfirmations;
use OrillaCart\Checkout\Application\PayNow;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\CustomerView;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Notifications\Application\EmailSettingsService;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptHandles;
use OrillaCart\Ordering\Application\ReceiptLinkExpired;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Returns\Application\Returns;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\I18n\Translations;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use OrillaCart\Shipping\Application\ShipmentTracking;
use OrillaCart\Storefront\Application\LegalSettingsService;
use OrillaCart\Storefront\Infrastructure\WordPress\AddressChoices;
use OrillaCart\Storefront\Infrastructure\WordPress\AnalyticsEvents;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\KeptTokens;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\PrivatePages;
use OrillaCart\Storefront\Infrastructure\WordPress\ReviewLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontEmailConfirmationLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;
use OrillaCart\Tax\Application\ExemptionCertificates;

/**
 * The checkout page, and the receipt customers come back to after ordering (?orillacart_order=a signed link).
 *
 * The summary is priced for the addresses in the query string (shipping_country, shipping_state,
 * shipping_postcode, shipping_city, billing_country, billing_state, billing_postcode, billing_city, rate), which the storefront script sends when the customer changes them;
 * the first view uses the customer's saved addresses, or the shop's country.
 */
final readonly class CheckoutBlock implements BlockDefinition
{
    public function __construct(
        private CheckoutService $checkout,
        private OrderService $orders,
        private CustomerService $customers,
        private SettingsService $settings,
        private AddressChoices $addresses,
        private Shopper $shopper,
        private ShopLinks $links,
        private TemplateRenderer $templates,
        private Translations $translations,
        private ShipmentTracking $shipments,
        private PayNow $payNow,
        private Invoices $invoices,
        private EmailSettingsService $emails,
        private AddressChecks $addressChecks,
        private Returns $returns,
        private Newsletter $newsletter,
        private ReviewLinks $reviewLinks,
        private AnalyticsEvents $analytics,
        private ExemptionCertificates $certificates,
        private GeographyService $geography,
        private LegalSettingsService $legal,
        private ?CheckoutPrefills $prefills = null,
    ) {}

    public static function name(): string
    {
        return 'orillacart/checkout';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        // The shopper's cart, or their order: on whatever page it is, page caches keep none of it.
        PrivatePages::markPrivate();
        // From the receipt's link, kept in the browser since it was opened (KeptTokens).
        $receipt = KeptTokens::token(StorefrontOrderLinks::RECEIPT_QUERY);
        if ($receipt !== null) {
            return $this->receipt($receipt);
        }

        // From the link that confirms a guest's email for ordering, on whatever device their mail is read: a
        // button confirms it, so a mail program that only fetches the link confirms nothing.
        $confirming = $_GET[StorefrontEmailConfirmationLinks::QUERY] ?? null;
        if (\is_string($confirming)) {
            $token = sanitize_text_field(wp_unslash($confirming));

            return $this->templates->render('storefront/email-confirmation.html.twig', ['token' => preg_match('/^' . EmailConfirmations::PATTERN . '$/', $token) === 1 ? $token : '']);
        }

        if ($this->settings->current()->catalogOnly) {
            return $this->templates->render('storefront/catalog-only.html.twig', ['links' => $this->links->pages()]);
        }
        $customer = $this->customer();
        $shop = $this->settings->current();
        // A guest a shopper's agent handed the cart to starts with what the agent gave (design 75).
        $token = $customer === null ? $this->shopper->cart()->token : null;
        $given = $token === null ? null : $this->prefills?->forCart($token);
        $billing = $customer->billing ?? ($given === null ? null : new AddressData(
            $given['billing']['first_name'] ?? '',
            $given['billing']['last_name'] ?? '',
            $given['billing']['street'] ?? '',
            $given['billing']['city'] ?? '',
            $given['billing']['postcode'] ?? '',
            $given['billing']['country'] ?? $shop->shopCountry ?? '',
            $given['billing']['phone'] ?? '',
            $given['billing']['state'] ?? null,
        ));
        $shipping = $customer->shippingAddress ?? $billing;
        // Without a saved address, the form starts in the shop's country.
        $billingCountry = $billing !== null ? $billing->country : $shop->shopCountry;
        $billingState = $billing !== null ? $billing->state : $shop->shopState;

        $details = self::code('billing_country', 2) !== null ? self::askedDetails() : new CheckoutDetails(
            $shipping !== null ? $shipping->country : $billingCountry,
            $shipping !== null ? $shipping->state : $billingState,
            $billingCountry,
            $billingState,
            shippingPostcode: $shipping?->postcode,
            billingPostcode: $billing?->postcode,
            shippingCity: $shipping?->city,
            billingCity: $billing?->city,
        );
        try {
            $summary = $this->checkout->summary($this->shopper->cart(), $details);
            $problem = null;
        } catch (CheckoutRuleViolation $e) {
            $summary = null;
            $problem = $this->translations->text($e->text);
        }
        $links = $this->links->pages();

        return $this->templates->render('storefront/checkout.html.twig', [
            'summary' => $summary,
            'problem' => $problem,
            'email' => $customer->email ?? $given['email'] ?? '',
            'billing' => $billing ?? ['country' => $billingCountry, 'state' => $billingState],
            'shipping' => $shipping ?? ['country' => $billingCountry, 'state' => $billingState],
            'address_known' => $details->billingCountry !== null,
            'ship_elsewhere' => $billing !== null && $shipping !== null && get_object_vars($billing) !== get_object_vars($shipping),
            'logged_in' => $this->shopper->customerId() !== null,
            'saved_addresses' => $customer->addresses ?? [],
            'accounts' => $this->customers->accountMode(),
            'cart_reminders' => !\in_array(EmailKind::CartReminder->value, $this->emails->current()->disabled, true),
            'remind_ticked' => $customer->cartReminders ?? false,
            // One who signed up and has yet to confirm is told to look for the email, not offered the box again.
            'newsletter' => $customer !== null && ($this->newsletter->isSubscribed($customer->email) || $this->newsletter->isPending($customer->email)) ? null : $this->newsletter->offer(),
            'newsletter_pending' => $customer !== null && $this->newsletter->isPending($customer->email),
            'address_checks' => $this->addressChecks->any(),
            'login_url' => wp_login_url((string) get_permalink()),
            'links' => $links,
            'exemption_states' => $this->shopper->customerId() !== null && $this->certificates->accepts() ? $this->geography->states('US') : [],
            'legal' => $this->legal(),
        ] + $this->addresses->all()) . ($summary !== null && $summary->lines !== [] ? $this->analytics->beginCheckout($summary) : '');
    }

    /**
     * The order a link the shop made is for: only the customer was given it, by the checkout and the emails, so
     * it shows the order to whoever has it, for ReceiptLinks::VALID_DAYS. An older one offers a new one, by email.
     */
    /**
     * What checkout says of the law: the box accepting the terms, the pages it links (published ones only), the
     * order button's words.
     *
     * @return array{accept_terms: bool, terms: ?string, withdrawal: ?string, delivery: ?string, button: string}
     */
    private function legal(): array
    {
        $legal = $this->legal->current();
        // In the shopper's language where the page has a published version in it.
        $url = fn(?int $id): ?string => $id !== null && get_post_status($id) === 'publish' ? $this->links->pageUrl($id) : null;

        return [
            'accept_terms' => $legal->acceptTerms,
            'terms' => $url($legal->terms),
            'withdrawal' => $url($legal->withdrawal),
            'delivery' => $url($legal->delivery),
            'button' => $legal->orderButton,
        ];
    }

    /**
     * What the page's forms say they are for: the order's handle when the token went into the browser's cookies
     * (KeptTokens), so the page holds no token; the token itself only when the address still carried it.
     */
    private static function handle(string $receipt, int $orderNumber): string
    {
        return KeptTokens::kept(StorefrontOrderLinks::RECEIPT_QUERY) ? ReceiptHandles::of($orderNumber) : $receipt;
    }

    private function receipt(string $receipt): string
    {
        $links = $this->links->pages();
        if ($receipt === '') {
            return $this->templates->render('storefront/checkout.html.twig', ['summary' => null, 'problem' => __('This page shows your order for an hour after you open its link: open the link in your email again.', 'orillacart'), 'links' => $links, 'states' => []]);
        }
        try {
            $order = $this->orders->byReceipt($receipt);
        } catch (ReceiptLinkExpired) {
            return $this->templates->render('storefront/receipt-expired.html.twig', ['receipt' => self::handle($receipt, (int) strtok($receipt, '-')), 'links' => $links]);
        } catch (OrderNotFound) {
            return $this->templates->render('storefront/checkout.html.twig', ['summary' => null, 'problem' => __('There is no order at this address.', 'orillacart'), 'links' => $links, 'states' => []]);
        }

        return $this->templates->render('storefront/receipt.html.twig', [
            'order' => $order,
            'receipt' => self::handle($receipt, $order->number),
            'heading' => true,
            'links' => $links,
            'shipments' => $this->shipments->forOrder($order->number),
            'pay_methods' => $this->payNow->methods($order),
            'invoices' => $this->invoices->ofOrder($order->number),
            'returns' => $this->returns->forCustomer($order->number),
            'review_links' => $this->reviewLinks->forOrder($order),
            'newsletter_pending' => $this->newsletter->isPending($order->email),
        ]) . (\in_array($order->status, ['cancelled', 'failed'], true) ? '' : $this->analytics->purchase($order));
    }

    private function customer(): ?CustomerView
    {
        $id = $this->shopper->customerId();
        if ($id === null) {
            return null;
        }
        try {
            return $this->customers->account($id);
        } catch (CustomerNotFound) {
            return null;
        }
    }

    /**
     * The addresses the storefront script sent: billing always, shipping only when it differs.
     */
    private static function askedDetails(): CheckoutDetails
    {
        $billingCountry = self::code('billing_country', 2);
        $billingState = self::code('billing_state', 3);
        $shippingCountry = self::code('shipping_country', 2);
        $shipping = \is_string($_GET['shipping'] ?? null) && preg_match(ShippingOptionView::ID_PATTERN, $_GET['shipping']) === 1 ? $_GET['shipping'] : null;

        return new CheckoutDetails(
            $shippingCountry ?? $billingCountry,
            $shippingCountry === null ? $billingState : self::code('shipping_state', 3),
            $billingCountry,
            $billingState,
            $shipping,
            self::postcode($shippingCountry === null ? 'billing_postcode' : 'shipping_postcode'),
            \is_string($_GET['pickup_point'] ?? null) ? mb_substr(sanitize_text_field(wp_unslash($_GET['pickup_point'])), 0, 64) : null,
            \is_string($_GET['vat_number'] ?? null) ? mb_substr(sanitize_text_field(wp_unslash($_GET['vat_number'])), 0, 20) : null,
            \is_string($_GET['payment'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $_GET['payment']) === 1 ? $_GET['payment'] : null,
            \is_string($_GET['delivery_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['delivery_date']) === 1 ? $_GET['delivery_date'] : null,
            \is_string($_GET['delivery_slot'] ?? null) && preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $_GET['delivery_slot']) === 1 ? $_GET['delivery_slot'] : null,
            self::postcode('billing_postcode'),
            self::city($shippingCountry === null ? 'billing_city' : 'shipping_city'),
            self::city('billing_city'),
            self::fields(),
        );
    }

    /**
     * The answers the storefront script sent to the checkout's questions (fields[<id>]=…), for the add-ons' order
     * adjusters: text only, a checked box as "1"; the order's own check comes when it is placed.
     *
     * @return array<string, string>
     */
    private static function fields(): array
    {
        $fields = [];
        foreach (\is_array($_GET['fields'] ?? null) ? $_GET['fields'] : [] as $id => $answer) {
            if (!\is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1 || !\is_string($answer) || $answer === '' || \count($fields) >= 100) {
                continue;
            }
            $fields[$id] = mb_substr(sanitize_text_field(wp_unslash($answer)), 0, 2000);
        }

        return $fields;
    }

    private static function city(string $name): ?string
    {
        $value = $_GET[$name] ?? null;

        return \is_string($value) && $value !== '' ? mb_substr(sanitize_text_field(wp_unslash($value)), 0, 100) : null;
    }

    private static function postcode(string $name): ?string
    {
        $value = $_GET[$name] ?? null;

        return \is_string($value) && preg_match('/^[A-Za-z0-9 -]{1,20}$/', $value) === 1 ? $value : null;
    }

    private static function code(string $name, int $length): ?string
    {
        $value = $_GET[$name] ?? null;

        return \is_string($value) && preg_match('/^[A-Z0-9]{1,' . $length . '}$/', $value) === 1 ? $value : null;
    }
}
