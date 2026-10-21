<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Checkout\Application\AddressChecks;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\PayNow;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\CustomerView;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Notifications\Application\EmailSettingsService;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinkExpired;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Returns\Application\Returns;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use OrillaCart\Shipping\Application\ShipmentTracking;
use OrillaCart\Storefront\Infrastructure\WordPress\AddressChoices;
use OrillaCart\Storefront\Infrastructure\WordPress\AnalyticsEvents;
use OrillaCart\Storefront\Infrastructure\WordPress\ReviewLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;

/**
 * The checkout page, and the receipt customers come back to after ordering (?orillacart_order=a signed link).
 *
 * The summary is priced for the addresses in the query string (shipping_country, shipping_state,
 * shipping_postcode, billing_country, billing_state, billing_postcode, rate), which the storefront script sends when the customer changes them;
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
        private ShipmentTracking $shipments,
        private PayNow $payNow,
        private Invoices $invoices,
        private EmailSettingsService $emails,
        private AddressChecks $addressChecks,
        private Returns $returns,
        private Newsletter $newsletter,
        private ReviewLinks $reviewLinks,
        private AnalyticsEvents $analytics,
    ) {}

    public static function name(): string
    {
        return 'orillacart/checkout';
    }

    public function render(array $attributes): string
    {
        $receipt = $_GET[StorefrontOrderLinks::RECEIPT_QUERY] ?? null;
        if (\is_string($receipt)) {
            return $this->receipt(sanitize_text_field(wp_unslash($receipt)));
        }

        if ($this->settings->current()->catalogOnly) {
            return $this->templates->render('storefront/catalog-only.html.twig', ['links' => $this->links->pages()]);
        }
        $customer = $this->customer();
        $shop = $this->settings->current();
        $billing = $customer->billing ?? null;
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
        );
        try {
            $summary = $this->checkout->summary($this->shopper->cart(), $details);
            $problem = null;
        } catch (CheckoutRuleViolation $e) {
            $summary = null;
            $problem = $e->getMessage();
        }
        $links = $this->links->pages();

        return $this->templates->render('storefront/checkout.html.twig', [
            'summary' => $summary,
            'problem' => $problem,
            'email' => $customer->email ?? '',
            'billing' => $billing ?? ['country' => $billingCountry, 'state' => $billingState],
            'shipping' => $shipping ?? ['country' => $billingCountry, 'state' => $billingState],
            'address_known' => $details->billingCountry !== null,
            'ship_elsewhere' => $billing !== null && $shipping !== null && get_object_vars($billing) !== get_object_vars($shipping),
            'logged_in' => $this->shopper->customerId() !== null,
            'saved_addresses' => $customer->addresses ?? [],
            'accounts' => $this->customers->accountMode(),
            'cart_reminders' => !\in_array(EmailKind::CartReminder->value, $this->emails->current()->disabled, true),
            'remind_ticked' => $customer->cartReminders ?? false,
            'newsletter' => $customer !== null && $this->newsletter->isSubscribed($customer->email) ? null : $this->newsletter->offer(),
            'address_checks' => $this->addressChecks->any(),
            'login_url' => wp_login_url((string) get_permalink()),
            'links' => $links,
        ] + $this->addresses->all()) . ($summary !== null && $summary->lines !== [] ? $this->analytics->beginCheckout($summary) : '');
    }

    /**
     * The order a link the shop made is for: only the customer was given it, by the checkout and the emails, so
     * it shows the order to whoever has it, for ReceiptLinks::VALID_DAYS. An older one offers a new one, by email.
     */
    private function receipt(string $receipt): string
    {
        $links = $this->links->pages();
        try {
            $order = $this->orders->byReceipt($receipt);
        } catch (ReceiptLinkExpired) {
            return $this->templates->render('storefront/receipt-expired.html.twig', ['receipt' => $receipt, 'links' => $links]);
        } catch (OrderNotFound) {
            return $this->templates->render('storefront/checkout.html.twig', ['summary' => null, 'problem' => __('There is no order at this address.', 'orillacart'), 'links' => $links, 'states' => []]);
        }

        return $this->templates->render('storefront/receipt.html.twig', [
            'order' => $order,
            'receipt' => $receipt,
            'heading' => true,
            'links' => $links,
            'shipments' => $this->shipments->forOrder($order->number),
            'pay_methods' => $this->payNow->methods($order),
            'invoices' => $this->invoices->ofOrder($order->number),
            'returns' => $this->returns->forOrder($order->number),
            'review_links' => $this->reviewLinks->forOrder($order, $receipt),
        ]) . (\in_array($order->status, ['cancelled', 'failed'], true) ? '' : $this->analytics->purchase($order));
    }

    private function customer(): ?CustomerView
    {
        $id = $this->shopper->customerId();
        if ($id === null) {
            return null;
        }
        try {
            return $this->customers->customer($id);
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
        );
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
