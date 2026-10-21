<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Checkout\Application\PayNow;
use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Notifications\Application\EmailSettingsService;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Ordering\Application\Downloads;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\AddressChoices;
use OrillaCart\Storefront\Infrastructure\WordPress\ReviewLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontAccountLinks;

/**
 * The customer's account page. Visitors see WordPress's login form and, unless the shop makes no accounts,
 * a form to make one. Customers see their orders (?section=orders, or ?order=ID for one), their files
 * (?section=downloads), their address book (?section=addresses) and the emails they want (?section=emails).
 */
final readonly class AccountBlock implements BlockDefinition
{
    private const int ORDERS_PER_PAGE = 10;

    public function __construct(
        private CustomerService $customers,
        private OrderService $orders,
        private Downloads $downloads,
        private AddressChoices $addresses,
        private Shopper $shopper,
        private ShopLinks $links,
        private TemplateRenderer $templates,
        private PayNow $payNow,
        private Invoices $invoices,
        private ReceiptLinks $receipts,
        private SettingsService $settings,
        private Newsletter $newsletter,
        private EmailSettingsService $emails,
        private ReviewLinks $reviewLinks,
    ) {}

    public static function name(): string
    {
        return 'orillacart/account';
    }

    public function render(array $attributes): string
    {
        $here = (string) get_permalink();
        $links = $this->links->pages();
        $customerId = $this->shopper->customerId();
        if ($customerId === null) {
            return $this->templates->render('storefront/account.html.twig', [
                'logged_in' => false,
                'accounts' => $this->customers->accountMode(),
                // The theme's button style for WordPress's login button, like the shop's own buttons.
                'login_form' => str_replace('class="button button-primary"', 'class="button button-primary wp-element-button"', (string) wp_login_form(['echo' => false, 'redirect' => $here, 'form_id' => 'orillacart-login', 'remember' => true])),
                'lost_password_url' => wp_lostpassword_url($here),
                'confirm_token' => \is_string($_GET[StorefrontAccountLinks::CONFIRM_QUERY] ?? null) && preg_match('/^[a-f0-9]{64}$/', $_GET[StorefrontAccountLinks::CONFIRM_QUERY]) === 1 ? $_GET[StorefrontAccountLinks::CONFIRM_QUERY] : null,
                'links' => $links,
            ]);
        }

        $orderId = self::number('order');
        $section = $orderId > 0 ? 'order' : (\is_string($_GET['section'] ?? null) ? sanitize_key($_GET['section']) : 'orders');
        $section = \in_array($section, ['orders', 'order', 'downloads', 'addresses', 'emails'], true) ? $section : 'orders';
        $context = [
            'logged_in' => true,
            'section' => $section,
            'links' => $links,
            'urls' => [
                'orders' => $here,
                'downloads' => add_query_arg('section', 'downloads', $here),
                'addresses' => add_query_arg('section', 'addresses', $here),
                'emails' => add_query_arg('section', 'emails', $here),
                // wp_logout_url() returns the address escaped for HTML (&amp;); the template escapes it.
                'logout' => html_entity_decode(wp_logout_url($here), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ],
        ];

        return $this->templates->render('storefront/account.html.twig', $context + match ($section) {
            'order' => $this->order($customerId, $orderId),
            'downloads' => ['downloads' => $this->downloads->ofCustomer($customerId)],
            'addresses' => $this->addressForm($customerId),
            'emails' => $this->emailChoices($customerId),
            default => $this->orderList($customerId, $here),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function orderList(int $customerId, string $here): array
    {
        $page = $this->orders->orders(new OrderQuery(customerId: $customerId, page: max(1, self::number('orders-page')), perPage: self::ORDERS_PER_PAGE));
        $pageUrls = [];
        for ($number = 1; $number <= $page->pages; ++$number) {
            $pageUrls[$number] = $number === 1 ? $here : add_query_arg('orders-page', $number, $here);
        }
        $orderUrls = [];
        foreach ($page->orders as $order) {
            $orderUrls[$order->number] = add_query_arg('order', $order->number, $here);
        }

        return ['orders' => $page, 'order_urls' => $orderUrls, 'page_urls' => $pageUrls];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(int $customerId, int $orderId): array
    {
        try {
            $order = $this->orders->customerOrder($customerId, $orderId);
        } catch (OrderNotFound) {
            $order = null;
        }

        if ($order === null) {
            return ['order' => null];
        }
        $receipt = $this->receipts->issue($order->number);

        return ['order' => $order, 'receipt' => $receipt, 'pay_methods' => $this->payNow->methods($order), 'invoices' => $this->invoices->ofOrder($order->number), 'review_links' => $this->reviewLinks->forOrder($order, $receipt)];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressForm(int $customerId): array
    {
        try {
            $customer = $this->customers->customer($customerId);
        } catch (CustomerNotFound) {
            $customer = null;
        }
        $choices = $this->addresses->all();

        return [
            'customer' => $customer,
            'max_addresses' => AddressBook::MAX_ADDRESSES,
            'default_country' => $customer->shippingAddress->country ?? $this->settings->current()->shopCountry,
            'countries' => $choices['countries'],
            'states' => $choices['states'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailChoices(int $customerId): array
    {
        try {
            $customer = $this->customers->customer($customerId);
        } catch (CustomerNotFound) {
            $customer = null;
        }
        $subscribed = $customer?->newsletter->subscribed ?? false;

        return [
            'customer' => $customer,
            // One who signed up can leave even after the shop stopped offering it.
            'newsletter' => $this->newsletter->offer() ?? ($subscribed ? __('The shop\'s newsletter', 'orillacart') : null),
            'subscribed' => $subscribed,
            'cart_reminders' => !\in_array(EmailKind::CartReminder->value, $this->emails->current()->disabled, true),
        ];
    }

    private static function number(string $name): int
    {
        $value = $_GET[$name] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
