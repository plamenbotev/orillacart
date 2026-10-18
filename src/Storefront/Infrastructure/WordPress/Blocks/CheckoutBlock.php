<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\CustomerView;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\AddressChoices;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;

/**
 * The checkout page, and the receipt customers come back to after ordering (?orillacart_order=KEY).
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
    ) {}

    public static function name(): string
    {
        return 'orillacart/checkout';
    }

    public static function metadata(): array
    {
        return [
            'title' => __('Checkout', 'orillacart'),
            'description' => __('Addresses, shipping and payment for the cart, then the receipt.', 'orillacart'),
            'icon' => 'money-alt',
        ];
    }

    public function render(array $attributes): string
    {
        $key = $_GET[StorefrontOrderLinks::RECEIPT_QUERY] ?? null;
        if (\is_string($key)) {
            return $this->receipt($key);
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
            'accounts' => $this->customers->accountMode(),
            'login_url' => wp_login_url((string) get_permalink()),
            'links' => $links,
        ] + $this->addresses->all());
    }

    /**
     * The order whose key is in the address. A key is a secret only the customer was given, so it shows the order to whoever has it.
     */
    private function receipt(string $key): string
    {
        $links = $this->links->pages();
        try {
            $order = $this->orders->byKey(preg_match('/^[a-f0-9]{32}$/', $key) === 1 ? $key : '');
        } catch (OrderNotFound) {
            return $this->templates->render('storefront/checkout.html.twig', ['summary' => null, 'problem' => __('There is no order at this address.', 'orillacart'), 'links' => $links, 'states' => []]);
        }

        return $this->templates->render('storefront/receipt.html.twig', [
            'order' => $order,
            'heading' => true,
            'links' => $links,
            'country_names' => $this->addresses->all()['country_names'],
        ]);
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
        $rate = \is_string($_GET['rate'] ?? null) && ctype_digit($_GET['rate']) ? (int) $_GET['rate'] : 0;

        return new CheckoutDetails(
            $shippingCountry ?? $billingCountry,
            $shippingCountry === null ? $billingState : self::code('shipping_state', 3),
            $billingCountry,
            $billingState,
            $rate > 0 ? $rate : null,
            self::postcode($shippingCountry === null ? 'billing_postcode' : 'shipping_postcode'),
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
