<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Application\AbandonedCarts;
use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Notifications\Application\CartReminders;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\ShopperCurrency;

/**
 * The store through the REST routes: the shop sets up shipping and payment, a visitor fills a cart (kept by a
 * cookie), checks out and pays offline or at PayPal. PayPal's API is played by intercepting WordPress HTTP calls.
 */
final class CheckoutRestTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    /** @var list<array{method: string, url: string, body: string, headers: array<array-key, mixed>}> */
    private array $payPalCalls = [];
    /** How many tokens PayPal gave out; each is TOKEN-n. */
    private int $tokens = 0;
    /** A token PayPal no longer takes, as when it expired before the shop's cached copy did. */
    private ?string $expiredToken = null;
    /** PayPal refuses orders that come with their lines and address, as it does an address it does not know. */
    private bool $refusesDetails = false;
    private int $product;
    private int $rate;
    private ?\Closure $payPalFilter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        unset($_COOKIE[CartCookie::NAME]);
        $this->payPalFilter = $this->payPal(...);
        add_filter('pre_http_request', $this->payPalFilter, 10, 3);

        $this->actAs('administrator');
        $this->product = $this->post('Lamp');
        self::assertSame(200, $this->request('PUT', "/products/{$this->product}", ['price' => '33.33', 'weight' => '2.5', 'attributes' => [
            ['name' => 'Finish', 'options' => [['name' => 'Plain'], ['name' => 'Brass', 'adjustment' => '5']]],
        ]], nonce: true)->get_status());
        $stockroom = $this->id($this->request('POST', '/stockrooms', ['name' => 'Sofia'], nonce: true));
        $this->request('PUT', "/stock/product/{$this->product}", ['levels' => [['stockroom_id' => $stockroom, 'quantity' => 3]]], nonce: true);

        $carrier = $this->request('POST', '/carriers', ['name' => 'Courier', 'rates' => [['name' => 'Bulgaria', 'price' => '5', 'regions' => ['BG'], 'weight' => ['min' => '0', 'max' => '20']]]], nonce: true);
        self::assertSame(201, $carrier->get_status(), (string) wp_json_encode($carrier->get_data()));
        $rates = $this->data($carrier)['rates'];
        self::assertIsArray($rates);
        self::assertIsArray($rates[0]);
        self::assertIsInt($rates[0]['id']);
        $this->rate = $rates[0]['id'];

        foreach ([
            'cash_on_delivery' => ['name' => 'Cash on delivery', 'active' => true, 'countries' => ['BG'], 'receipt_note' => 'Have the cash ready.'],
            'paypal' => ['name' => 'PayPal', 'active' => true, 'position' => 2, 'settings' => ['client_id' => 'client', 'client_secret' => 'S3cr3t-value', 'sandbox' => true, 'webhook_id' => 'WH-1']],
        ] as $gateway => $method) {
            $saved = $this->request('PUT', "/payment-methods/{$gateway}", $method, nonce: true);
            self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        }
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        if ($this->payPalFilter !== null) {
            remove_filter('pre_http_request', $this->payPalFilter);
        }
        unset($_COOKIE[CartCookie::NAME]);
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyShopManagersSetUpShippingAndPayments(): void
    {
        $routes = [['GET', '/carriers'], ['POST', '/carriers'], ['GET', '/carriers/1'], ['PUT', '/carriers/1'], ['DELETE', '/carriers/1'],
            ['GET', '/payment-methods'], ['PUT', '/payment-methods/paypal'],
            ['GET', '/shipping-classes'], ['POST', '/shipping-classes'], ['PUT', '/shipping-classes/1'], ['DELETE', '/shipping-classes/1']];
        foreach ($routes as [$method, $route]) {
            self::assertSame(401, $this->request($method, $route)->get_status(), "visitor: {$method} {$route}");
        }
        $this->actAs('editor');
        foreach ($routes as [$method, $route]) {
            self::assertSame(403, $this->request($method, $route, nonce: true)->get_status(), "editor: {$method} {$route}");
        }

        $this->actAs('administrator');
        $methods = $this->data($this->request('GET', '/payment-methods'));
        // Saved positions first; methods never saved keep the default order. Ties go by gateway id.
        self::assertSame(['cash_on_delivery', 'bank_transfer', 'paypal', 'cheque', 'test_pay'], array_column($methods, 'gateway'), 'the add-on\'s gateway after the shop\'s own');
        self::assertStringNotContainsString('S3cr3t-value', (string) wp_json_encode($methods), 'the PayPal secret never leaves the server');
    }

    public function testRatesCarryOnlyTheShippingClassesTheyList(): void
    {
        $this->actAs('administrator');
        $bulky = $this->id($this->request('POST', '/shipping-classes', ['name' => 'Bulky'], nonce: true));
        self::assertSame(422, $this->request('POST', '/shipping-classes', ['name' => 'Bulky'], nonce: true)->get_status(), 'names are unique');
        self::assertSame(200, $this->request('PUT', "/products/{$this->product}", ['price' => '33.33', 'weight' => '2.5', 'shipping_class_id' => $bulky], nonce: true)->get_status());
        self::assertSame($bulky, $this->data($this->request('GET', "/products/{$this->product}"))['shipping_class_id']);
        self::assertSame(422, $this->request('PUT', "/products/{$this->product}", ['price' => '1', 'shipping_class_id' => 999999], nonce: true)->get_status());
        $freight = $this->request('POST', '/carriers', ['name' => 'Freight', 'rates' => [['name' => 'Pallet', 'price' => '40', 'shipping_classes' => [$bulky]]]], nonce: true);
        self::assertSame(201, $freight->get_status(), (string) wp_json_encode($freight->get_data()));
        self::assertSame([$bulky], $this->rows($this->data($freight)['rates'])[0]['shipping_classes']);
        $carrier = $this->id($freight);
        wp_set_current_user(0);

        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 1], nonce: true));
        $summary = $this->data($this->request('POST', '/checkout/summary', ['shipping_country' => 'BG'], nonce: true));
        self::assertSame(['Bulgaria', 'Pallet'], array_column($this->rows($summary['shipping_options']), 'name'));

        $this->actAs('administrator');
        $inUse = $this->request('DELETE', "/shipping-classes/{$bulky}", nonce: true);
        self::assertSame([422, 'orillacart_shipping_class_in_use'], [$inUse->get_status(), $this->data($inUse)['code']]);
        $this->request('PUT', "/carriers/{$carrier}", ['name' => 'Freight', 'rates' => [['name' => 'Pallet', 'price' => '40', 'shipping_classes' => [0]]]], nonce: true);
        self::assertSame(204, $this->request('DELETE', "/shipping-classes/{$bulky}", nonce: true)->get_status());
        self::assertNull($this->data($this->request('GET', "/products/{$this->product}"))['shipping_class_id'], 'the product left the class');
    }

    public function testARateForSomePostcodesShipsOnlyThere(): void
    {
        $this->actAs('administrator');
        $this->request('PUT', '/carriers/' . $this->carrierOf($this->rate), ['name' => 'Courier', 'rates' => [['id' => $this->rate, 'name' => 'Sofia', 'price' => '3', 'regions' => ['BG'], 'postcodes' => ['1000-1999']],
            ['name' => 'Outside Sofia', 'price' => '6', 'regions' => ['BG'], 'excluded_postcodes' => ['1000-1999']],
            ['name' => 'Abroad', 'price' => '20', 'excluded_regions' => ['BG']],
        ]], nonce: true);
        self::assertSame(422, $this->request('POST', '/carriers', ['name' => 'Post', 'rates' => [['name' => 'Wrong', 'price' => '1', 'excluded_regions' => ['XX']]]], nonce: true)->get_status(), 'no such country');
        self::assertSame(422, $this->request('POST', '/carriers', ['name' => 'Post', 'rates' => [['name' => 'Wrong', 'price' => '1', 'postcodes' => ['1999-1000']]]], nonce: true)->get_status());
        wp_set_current_user(0);
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 1], nonce: true));

        self::assertSame(['Outside Sofia'], $this->shippingNames(['shipping_country' => 'BG']), 'no postcode yet');
        self::assertSame(['Outside Sofia'], $this->shippingNames(['shipping_country' => 'BG', 'shipping_postcode' => '4000']));
        self::assertSame(['Abroad'], $this->shippingNames(['shipping_country' => 'DE']));
        self::assertSame(['Sofia'], $this->shippingNames(['shipping_country' => 'BG', 'shipping_postcode' => '1404']));

        $plovdiv = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Main St', 'city' => 'Plovdiv', 'postcode' => '4000', 'country' => 'BG', 'phone' => '+359 2 000 0000'];
        $refused = $this->request('POST', '/checkout', $this->order('cash_on_delivery') + ['shipping_address' => $plovdiv], nonce: true);
        self::assertSame([422, 'orillacart_shipping_required'], [$refused->get_status(), $this->data($refused)['code']]);
        $placed = $this->request('POST', '/checkout', $this->order('cash_on_delivery'), nonce: true);
        self::assertSame(201, $placed->get_status(), 'the billing address in Sofia (1000) is the shipping address');
    }

    public function testACartLeftAtCheckoutIsRemindedReportedAndRecoveredByItsOrder(): void
    {
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 1], nonce: true));
        self::assertSame(422, $this->request('POST', '/cart/checkout-contact', ['email' => 'not an email', 'remind' => true], nonce: true)->get_status());
        self::assertSame(204, $this->request('POST', '/cart/checkout-contact', ['email' => 'Ada@Example.com', 'remind' => true], nonce: true)->get_status());

        $container = self::kernel()->container();
        $abandoned = $container->get(AbandonedCarts::class);
        self::assertInstanceOf(AbandonedCarts::class, $abandoned);
        $left = $abandoned->mark(new \DateTimeImmutable('+5 hours'));
        self::assertCount(1, $left);
        self::assertSame([], $abandoned->mark(new \DateTimeImmutable('+5 hours')), 'counted once');
        $reminders = $container->get(CartReminders::class);
        self::assertInstanceOf(CartReminders::class, $reminders);
        $reminders->send($left[0]);
        self::assertSame(['ada@example.com'], array_column($this->mails, 'to'));
        self::assertStringContainsString('Your cart is waiting for you', $this->mails[0]['subject']);
        self::assertMatchesRegularExpression('/orillacart_cart_link=[a-f0-9]{64}/', $this->mails[0]['message']);

        $placed = $this->request('POST', '/checkout', $this->order('cash_on_delivery'), nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $this->actAs('administrator');
        $report = $this->data($this->request('GET', '/reports/carts?compare=none'));
        self::assertIsArray($report['totals']);
        self::assertSame([1, 1, 1, 1], [$report['totals']['abandoned'], $report['totals']['reminded'], $report['totals']['recovered'], $report['totals']['recovered_after_reminder']]);
        self::assertSame($this->data($placed)['total'], $report['totals']['recovered_value']);
        $latest = $this->rows($report['latest']);
        self::assertSame(['ada@example.com', $this->data($placed)['number']], [$latest[0]['email'], $latest[0]['order_id']]);
        wp_set_current_user(0);
        self::assertSame(401, $this->request('GET', '/reports/carts')->get_status());
    }

    public function testAVisitorOrdersAndPaysOnDelivery(): void
    {
        $added = $this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 2], nonce: true);
        self::assertSame(201, $added->get_status(), (string) wp_json_encode($added->get_data()));
        $this->keepCookie($added);
        self::assertSame(['items' => 2, 'subtotal' => '66.66'], array_intersect_key($this->data($this->request('GET', '/cart')), ['items' => 0, 'subtotal' => 0]));

        $summary = $this->data($this->request('POST', '/checkout/summary', ['shipping_country' => 'BG'], nonce: true));
        self::assertSame(['71.66', true], [$summary['total'], $summary['needs_shipping']]);
        self::assertSame(['cash_on_delivery', 'paypal'], array_column($this->rows($summary['payment_methods']), 'gateway'));

        $placed = $this->request('POST', '/checkout', $this->order('cash_on_delivery'), nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $order = $this->data($placed);
        self::assertSame(['on_hold', '71.66', null], [$order['status'], $order['total'], $order['redirect_url']]);
        self::assertSame(1, $this->stock()->stock(StockItemRef::product($this->product))->available);
        self::assertSame(0, $this->data($this->request('GET', '/cart'))['items']);

        // The shop's own copy goes to its address for emails, which the end-to-end setup may have changed, else the site's.
        $stored = get_option('orillacart_email_settings');
        $shop = \is_array($stored) && \is_string($stored['shop_address'] ?? null) && $stored['shop_address'] !== '' ? $stored['shop_address'] : get_option('admin_email');
        self::assertSame(['ada@example.com', $shop], array_column($this->sentMail(), 'to'));
        self::assertStringContainsString('We received your order', $this->sentMail()[0]['subject'] ?? '');
        self::assertStringContainsString('Have the cash ready.', $this->sentMail()[0]['message'] ?? '');

        $receipt = $this->request('GET', "/receipts/{$this->key($order)}");
        self::assertSame(200, $receipt->get_status());
        self::assertSame(['Cash on delivery', 'Have the cash ready.', 'Courier – Bulgaria'], [$this->data($receipt)['payment_name'], $this->data($receipt)['payment_note'], $this->data($receipt)['shipping']]);
        self::assertSame(404, $this->request('GET', '/receipts/1-1-' . str_repeat('0', 32))->get_status());
    }

    public function testRefusalsAndValidationMessagesAreInTheSitesLanguage(): void
    {
        $words = [
            'This code is not valid.' => 'Кодът не е валиден.',
            'Enter the code.' => 'Въведете кода.',
            'This value is too long. It should have {{ limit }} character or less.' => 'Твърде дълго: до {{ limit }} знак.',
            'This value is too long. It should have {{ limit }} characters or less.' => 'Твърде дълго: до {{ limit }} знака.',
        ];
        $translate = static fn(string $translation, string $text, string $domain): string => $domain === 'orillacart' ? ($words[$text] ?? $translation) : $translation;
        $plural = static fn(string $translation, string $single, string $plural, int $count, string $domain): string => $domain === 'orillacart' ? ($words[$count === 1 ? $single : $plural] ?? $translation) : $translation;
        add_filter('gettext', $translate, 10, 3);
        add_filter('ngettext', $plural, 10, 5);
        try {
            $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 1], nonce: true));
            $refused = $this->request('POST', '/cart/coupons', ['code' => 'NOPE'], nonce: true);
            self::assertSame('Кодът не е валиден.', $this->data($refused)['message']);

            $blank = $this->data($this->request('POST', '/cart/gift-cards', ['code' => ''], nonce: true));
            self::assertSame('Въведете кода.', $this->rows($this->rows([$blank['data']])[0]['violations'])[0]['message'], 'a validation message of the DTO');
            $long = $this->data($this->request('POST', '/cart/gift-cards', ['code' => str_repeat('A', 41)], nonce: true));
            self::assertSame('Твърде дълго: до 40 знака.', $this->rows($this->rows([$long['data']])[0]['violations'])[0]['message'], "Symfony's own, with its plural");
        } finally {
            remove_filter('gettext', $translate, 10);
            remove_filter('ngettext', $plural, 10);
        }
    }

    public function testAVisitorUsesACouponAndTheOrderKeepsIt(): void
    {
        $this->actAs('administrator');
        $made = $this->request('POST', '/coupons', ['code' => 'spring', 'kind' => 'percent', 'amount' => '10', 'description' => 'Spring sale', 'usage_limit' => 1], nonce: true);
        self::assertSame(201, $made->get_status(), (string) wp_json_encode($made->get_data()));
        self::assertSame(422, $this->request('POST', '/coupons', ['code' => 'SPRING', 'kind' => 'percent', 'amount' => '5'], nonce: true)->get_status(), 'one coupon per code');
        wp_set_current_user(0);

        $added = $this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 2], nonce: true);
        $this->keepCookie($added);
        $refused = $this->request('POST', '/cart/coupons', ['code' => 'NOPE'], nonce: true);
        self::assertSame([422, 'This code is not valid.'], [$refused->get_status(), $this->data($refused)['message']]);
        $applied = $this->request('POST', '/cart/coupons', ['code' => 'spring'], nonce: true);
        self::assertSame(200, $applied->get_status(), (string) wp_json_encode($applied->get_data()));

        $summary = $this->data($this->request('POST', '/checkout/summary', ['shipping_country' => 'BG'], nonce: true));
        self::assertSame(['66.66', '6.67', '64.99'], [$summary['subtotal'], $summary['discount'], $summary['total']]);

        $placed = $this->request('POST', '/checkout', $this->order('cash_on_delivery'), nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $receipt = $this->data($this->request('GET', "/receipts/{$this->key($this->data($placed))}"));
        self::assertSame(['6.67', 'SPRING'], [$receipt['discount_total'], $this->rows($receipt['discounts'])[0]['code']]);
        self::assertStringContainsString('Coupon SPRING', $this->sentMail()[0]['message'] ?? '');

        $this->actAs('administrator');
        $coupon = $this->rows($this->data($this->request('GET', '/coupons'))['coupons'])[0];
        self::assertSame(['SPRING', 1], [$coupon['code'], $coupon['uses']]);
        self::assertIsInt($coupon['id']);

        // Cancelling gives the use back, with the coupon locked as it was when the use was counted.
        $number = $this->data($placed)['number'];
        self::assertIsInt($number);
        $cancelled = $this->request('PUT', "/orders/{$number}/status", ['status' => 'cancelled'], nonce: true);
        self::assertSame(200, $cancelled->get_status(), (string) wp_json_encode($cancelled->get_data()));
        self::assertSame(0, $this->rows($this->data($this->request('GET', '/coupons'))['coupons'])[0]['uses']);
        self::assertSame(204, $this->request('DELETE', "/coupons/{$coupon['id']}", nonce: true)->get_status());
        self::assertSame('SPRING', $this->rows($this->data($this->request('GET', "/receipts/{$this->key($this->data($placed))}"))['discounts'])[0]['code'], 'the order keeps it');
    }

    public function testAVisitorPaysPartWithAGiftCardAndCancellingGivesItBack(): void
    {
        $this->actAs('administrator');
        $issued = $this->request('POST', '/gift-cards', ['amount' => '20', 'recipient_email' => 'ada@example.com'], nonce: true);
        self::assertSame(201, $issued->get_status(), (string) wp_json_encode($issued->get_data()));
        $code = $this->data($issued)['code'];
        self::assertIsString($code);
        $card = $this->data($issued)['card'];
        self::assertIsArray($card);
        self::assertIsInt($card['id']);
        self::assertStringContainsString($code, $this->sentMail()[0]['message'] ?? '', 'the code went to whom it is for');
        wp_set_current_user(0);

        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 1], nonce: true));
        $refused = $this->request('POST', '/cart/gift-cards', ['code' => 'AAAA-BBBB-CCCC-DDDD'], nonce: true);
        self::assertSame([422, 'This gift card is not valid.'], [$refused->get_status(), $this->data($refused)['message']]);
        $applied = $this->request('POST', '/cart/gift-cards', ['code' => $code], nonce: true);
        self::assertSame(200, $applied->get_status(), (string) wp_json_encode($applied->get_data()));

        $summary = $this->data($this->request('POST', '/checkout/summary', ['shipping_country' => 'BG'], nonce: true));
        self::assertSame(['38.33', '20', '18.33'], [$summary['total'], $summary['gift_card_total'], $summary['amount_due']]);
        $placed = $this->request('POST', '/checkout', $this->order('cash_on_delivery'), nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $receipt = $this->data($this->request('GET', "/receipts/{$this->key($this->data($placed))}"));
        self::assertSame(['20', '18.33'], [$receipt['gift_card_total'], $receipt['amount_due']]);

        $this->actAs('administrator');
        self::assertSame('0', $this->balanceOf($card['id']));
        $number = $this->data($placed)['number'];
        self::assertIsInt($number);
        self::assertSame(200, $this->request('PUT', "/orders/{$number}/status", ['status' => 'cancelled'], nonce: true)->get_status());
        self::assertSame('20', $this->balanceOf($card['id']), 'the cancelled order gave it back');
        self::assertCount(3, $this->rows($this->data($this->request('GET', "/gift-cards/{$card['id']}"))['entries']));
    }

    private function balanceOf(int $card): mixed
    {
        $detail = $this->data($this->request('GET', "/gift-cards/{$card}"))['card'];
        self::assertIsArray($detail);

        return $detail['balance'] ?? null;
    }

    public function testAShopperWhoChoseAnotherCurrencyOrdersInIt(): void
    {
        $this->actAs('administrator');
        $saved = $this->request('PUT', '/currencies', ['offered' => [['code' => 'USD', 'rate' => '1.1', 'rounding' => 'ninety_nine']]], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        self::assertSame(422, $this->request('PUT', '/currencies', ['offered' => [['code' => 'USD', 'rate' => '0']]], nonce: true)->get_status());
        wp_set_current_user(0);
        $_COOKIE[ShopperCurrency::COOKIE] = 'USD';

        try {
            $added = $this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 1], nonce: true);
            $this->keepCookie($added);
            $cart = $this->data($this->request('GET', '/cart'));
            self::assertSame(['USD', '36.99'], [$cart['currency'], $cart['subtotal']], '33.33 EUR at 1.1, just under the next dollar');
            $summary = $this->data($this->request('POST', '/checkout/summary', ['shipping_country' => 'BG'], nonce: true));
            self::assertSame(['USD', '36.99', '5.5', '42.49'], [$summary['currency'], $summary['subtotal'], $summary['shipping'], $summary['total']]);

            $placed = $this->request('POST', '/checkout', $this->order('cash_on_delivery'), nonce: true);
            self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
            $receipt = $this->data($this->request('GET', "/receipts/{$this->key($this->data($placed))}"));
            self::assertSame(['USD', '42.49'], [$receipt['currency'], $receipt['total']]);
        } finally {
            unset($_COOKIE[ShopperCurrency::COOKIE]);
            delete_option('orillacart_currencies');
        }
    }

    public function testAnOrderThatRunsOutOfStockWhilePlacedTakesNothing(): void
    {
        $this->actAs('administrator');
        $offered = $this->rows($this->data($this->request('GET', "/products/{$this->product}"))['offered']);
        wp_set_current_user(0);
        [$plain, $brass] = array_column($this->rows($offered[0]['options']), 'id');

        // Each line alone is in stock (3 lamps); together they are not, which only taking the stock finds out.
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product, 'option_ids' => [$plain], 'quantity' => 2], nonce: true));
        $this->request('POST', '/cart/items', ['product_id' => $this->product, 'option_ids' => [$brass], 'quantity' => 2], nonce: true);

        $placed = $this->request('POST', '/checkout', $this->order('cash_on_delivery'), nonce: true);

        self::assertSame(422, $placed->get_status());
        self::assertSame('orillacart_out_of_stock', $this->data($placed)['code']);
        self::assertSame(3, $this->stock()->stock(StockItemRef::product($this->product))->available, 'the first line\'s stock was rolled back');
        self::assertSame('0', $this->tableRows('orders'));
        self::assertSame(4, $this->data($this->request('GET', '/cart'))['items'], 'the next request works: the database layer was not left broken');
    }

    public function testEveryChangeToTheCartGivesItsCookieAgain(): void
    {
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));
        $token = $_COOKIE[CartCookie::NAME] ?? null;

        $again = $this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true);
        $this->keepCookie($again);
        self::assertSame($token, $_COOKIE[CartCookie::NAME] ?? null, 'the same cart, for another month');
        self::assertArrayNotHasKey('Set-Cookie', $this->request('GET', '/cart')->get_headers(), 'reading it changes nothing');
    }

    public function testAVisitorPaysWithPayPal(): void
    {
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));

        $order = $this->data($this->request('POST', '/checkout', $this->order('paypal'), nonce: true));
        self::assertSame(['pending_payment', 'https://www.sandbox.paypal.com/checkoutnow?token=PAY-1'], [$order['status'], $order['redirect_url']]);
        $creates = array_values(array_filter($this->payPalCalls, static fn(array $call): bool => $call['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'));
        self::assertCount(1, $creates);
        $create = $creates[0];
        $body = json_decode($create['body'], true);
        self::assertSame(['EUR', '38.33'], [self::at($body, 'purchase_units', 0, 'amount', 'currency_code'), self::at($body, 'purchase_units', 0, 'amount', 'value')]);
        self::assertIsInt($order['number']);
        self::assertSame((string) $order['number'], self::at($body, 'purchase_units', 0, 'custom_id'));
        self::assertSame(['item_total' => ['currency_code' => 'EUR', 'value' => '33.33'], 'shipping' => ['currency_code' => 'EUR', 'value' => '5.00']], self::at($body, 'purchase_units', 0, 'amount', 'breakdown'));
        self::assertSame([['name' => 'Lamp', 'quantity' => '1', 'unit_amount' => ['currency_code' => 'EUR', 'value' => '33.33']]], self::at($body, 'purchase_units', 0, 'items'));
        self::assertSame([['type' => 'SHIPPING', 'name' => ['full_name' => 'Ada Lovelace'], 'address' => ['address_line_1' => '1 Vitosha Blvd', 'admin_area_2' => 'Sofia', 'postal_code' => '1000', 'country_code' => 'BG']], 'SET_PROVIDED_ADDRESS'], [self::at($body, 'purchase_units', 0, 'shipping'), self::at($body, 'payment_source', 'paypal', 'experience_context', 'shipping_preference')]);
        $returnUrl = self::at($body, 'payment_source', 'paypal', 'experience_context', 'return_url');
        self::assertIsString($returnUrl);
        self::assertStringStartsWith(rest_url('orillacart/v1/payments/paypal/return') . '?', $returnUrl);
        parse_str((string) parse_url($returnUrl, \PHP_URL_QUERY), $signed);

        $forged = new \WP_REST_Request('GET', self::NAMESPACE . '/payments/paypal/return');
        $forged->set_query_params(['token' => 'PAY-1', 'PayerID' => 'PAYER']);
        self::assertSame(404, rest_do_request($forged)->get_status(), 'an address the shop did not sign');

        $return = new \WP_REST_Request('GET', self::NAMESPACE . '/payments/paypal/return');
        $return->set_query_params(['token' => 'PAY-1', 'PayerID' => 'PAYER'] + $signed);
        $back = rest_do_request($return);
        self::assertSame(303, $back->get_status());
        self::assertSame($order['receipt_url'], $back->get_headers()['Location']);
        self::assertSame('processing', $this->data($this->request('GET', "/receipts/{$this->key($order)}"))['status']);
    }

    public function testAnOrderPayPalRefusesTheDetailsOfIsPaidForItsAmountAlone(): void
    {
        $this->refusesDetails = true;
        $this->expectOutputRegex('/PayPal refused the details of order .* \(POSTAL_CODE_REQUIRED\)/');
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));

        $order = $this->data($this->request('POST', '/checkout', $this->order('paypal'), nonce: true));

        self::assertSame('pending_payment', $order['status']);
        $creates = array_values(array_filter($this->payPalCalls, static fn(array $call): bool => $call['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'));
        self::assertCount(2, $creates);
        $body = json_decode($creates[1]['body'], true);
        self::assertSame([['currency_code' => 'EUR', 'value' => '38.33'], 'NO_SHIPPING'], [self::at($body, 'purchase_units', 0, 'amount'), self::at($body, 'payment_source', 'paypal', 'experience_context', 'shipping_preference')]);
    }

    public function testTheShopRefundsThroughPayPalAndATokenPayPalDroppedIsFetchedAgain(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%orillacart\\_paypal\\_%'");
        wp_cache_flush();
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));
        $order = $this->data($this->request('POST', '/checkout', $this->order('paypal'), nonce: true));
        $return = new \WP_REST_Request('GET', self::NAMESPACE . '/payments/paypal/return');
        $created = array_values(array_filter($this->payPalCalls, static fn(array $call): bool => str_ends_with($call['url'], '/v2/checkout/orders')));
        $returnUrl = self::at(json_decode($created[0]['body'], true), 'payment_source', 'paypal', 'experience_context', 'return_url');
        self::assertIsString($returnUrl);
        parse_str((string) parse_url($returnUrl, \PHP_URL_QUERY), $signed);
        $return->set_query_params(['token' => 'PAY-1', 'PayerID' => 'PAYER'] + $signed);
        self::assertSame(303, rest_do_request($return)->get_status());
        self::assertSame(1, $this->tokens, 'one token for creating and capturing');

        $this->expiredToken = 'TOKEN-1';
        $this->actAs('administrator');
        $number = $order['number'];
        self::assertIsInt($number);
        $refunded = $this->request('POST', "/orders/{$number}/refunds", ['amount' => '10', 'reason' => 'Scratched.'], nonce: true);

        self::assertSame(201, $refunded->get_status(), (string) wp_json_encode($refunded->get_data()));
        self::assertSame(2, $this->tokens, 'fetched again after PayPal refused the old one');
        $refunds = array_values(array_filter($this->payPalCalls, static fn(array $call): bool => str_ends_with($call['url'], '/v2/payments/captures/CAP-1/refund')));
        self::assertCount(2, $refunds, 'refused once, then sent again with the new token');
        $body = json_decode($refunds[1]['body'], true);
        self::assertIsArray($body);
        self::assertSame(['value' => '10.00', 'currency_code' => 'EUR'], $body['amount']);
        self::assertSame('Scratched.', $body['note_to_payer']);
        self::assertSame($body['custom_id'], $refunds[1]['headers']['PayPal-Request-Id'] ?? null, 'one request id: PayPal refunds it once, and its webhook names it');
        self::assertSame('Bearer TOKEN-2', $refunds[1]['headers']['Authorization'] ?? null);
    }

    public function testPayPalWebhooksAreReadOnlyWhenPayPalSignedThem(): void
    {
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));
        $order = $this->data($this->request('POST', '/checkout', $this->order('paypal'), nonce: true));

        $forged = new \WP_REST_Request('POST', self::NAMESPACE . '/payments/paypal/webhook');
        $forged->set_header('Content-Type', 'application/json');
        $forged->set_header('PayPal-Transmission-Sig', 'forged');
        $forged->set_body((string) wp_json_encode(['event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'PAY-1']]));
        $response = rest_do_request($forged);

        self::assertSame(400, $response->get_status(), 'not from PayPal: a bad request, not one to send again');
        self::assertSame('pending_payment', $this->data($this->request('GET', "/receipts/{$this->key($order)}"))['status']);

        $forged->set_header('PayPal-Transmission-Sig', 'genuine');
        self::assertSame(200, rest_do_request($forged)->get_status());
        self::assertSame('processing', $this->data($this->request('GET', "/receipts/{$this->key($order)}"))['status']);
    }

    public function testAnAddOnsGatewayTakesPaymentsThroughTheExtensionApi(): void
    {
        $this->actAs('administrator');
        $methods = $this->rows($this->data($this->request('GET', '/payment-methods')));
        $byGateway = array_column($methods, null, 'gateway');
        self::assertSame(['api_key', 'mode', 'fee'], array_column($this->rows($byGateway['test_pay']['fields'] ?? []), 'key'));
        self::assertNotNull($byGateway['test_pay']['notification_url'] ?? null);
        foreach ([['mode' => 'weekly'], ['fee' => 'a lot']] as $wrong) {
            self::assertSame(422, $this->request('PUT', '/payment-methods/test_pay', ['name' => 'Test Pay', 'active' => true, 'settings' => ['api_key' => 'k3y'] + $wrong], nonce: true)->get_status(), (string) wp_json_encode($wrong));
        }
        $saved = $this->request('PUT', '/payment-methods/test_pay', ['name' => 'Test Pay', 'active' => true, 'position' => 3, 'settings' => ['api_key' => 'k3y', 'mode' => 'test', 'fee' => '1.5']], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        self::assertStringNotContainsString('k3y', (string) wp_json_encode($saved->get_data()));
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $stored = $wpdb->get_var($wpdb->prepare('SELECT settings FROM %i WHERE gateway = %s', $wpdb->prefix . 'orillacart_payment_methods', 'test_pay'));
        self::assertIsString($stored);
        self::assertStringContainsString('"sealed"', $stored, 'the settings are sealed in the database');
        self::assertStringNotContainsString('k3y', $stored);
        wp_set_current_user(0);

        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));
        $paid = $this->data($this->request('POST', '/checkout', $this->order('test_pay'), nonce: true));
        self::assertIsString($paid['redirect_url']);
        self::assertSame(1, preg_match('/ref=(REF-\d+)/', $paid['redirect_url'], $found));
        parse_str((string) parse_url($paid['redirect_url'], \PHP_URL_QUERY), $redirect);
        self::assertIsString($redirect['back'] ?? null);
        parse_str((string) parse_url($redirect['back'], \PHP_URL_QUERY), $signed);
        $return = new \WP_REST_Request('GET', self::NAMESPACE . '/payments/test_pay/return');
        $return->set_query_params(['ref' => $found[1]] + $signed);
        $back = rest_do_request($return);
        self::assertSame([303, $paid['receipt_url']], [$back->get_status(), $back->get_headers()['Location'] ?? null]);
        self::assertSame('processing', $this->data($this->request('GET', "/receipts/{$this->key($paid)}"))['status']);

        unset($_COOKIE[CartCookie::NAME]);
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));
        $waiting = $this->data($this->request('POST', '/checkout', $this->order('test_pay'), nonce: true));
        self::assertIsString($waiting['redirect_url']);
        self::assertSame(1, preg_match('/ref=(REF-\d+)/', $waiting['redirect_url'], $found));
        $body = (string) wp_json_encode(['ref' => $found[1], 'status' => 'paid', 'amount' => '38.33']);
        $notification = new \WP_REST_Request('POST', self::NAMESPACE . '/payments/test_pay/webhook');
        $notification->set_header('Content-Type', 'application/json');
        $notification->set_body($body);
        $notification->set_header('X-Test-Signature', 'forged');
        self::assertSame(400, rest_do_request($notification)->get_status());
        $notification->set_header('X-Test-Signature', hash_hmac('sha256', $body, 'k3y'));
        self::assertSame(200, rest_do_request($notification)->get_status());
        self::assertSame('processing', $this->data($this->request('GET', "/receipts/{$this->key($waiting)}"))['status']);

        self::assertSame(404, rest_do_request(new \WP_REST_Request('GET', self::NAMESPACE . '/payments/nobody/return'))->get_status());
    }

    public function testACarrierPricedByAnAddOnsMethodShipsTheOrder(): void
    {
        $this->actAs('administrator');
        self::assertContains('test_ship', array_column($this->rows($this->data($this->request('GET', '/shipping-methods'))), 'id'));
        $created = $this->request('POST', '/carriers', ['name' => 'Speedy', 'position' => 5, 'method' => 'test_ship', 'method_settings' => ['account' => 'A-1', 'per_kg' => '2']], nonce: true);
        self::assertSame(201, $created->get_status(), (string) wp_json_encode($created->get_data()));
        $carrier = $this->id($created);
        self::assertSame(422, $this->request('PUT', "/carriers/{$carrier}", ['name' => 'Speedy', 'method' => 'test_ship', 'method_settings' => ['per_kg' => 'two']], nonce: true)->get_status());
        wp_set_current_user(0);

        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product, 'quantity' => 2], nonce: true));
        $summary = $this->data($this->request('POST', '/checkout/summary', ['shipping_country' => 'BG'], nonce: true));
        $options = array_column($this->rows($summary['shipping_options']), 'name', 'id');
        // 2 lamps of 2.5 kg: 3 + 2 × 5 kg
        self::assertSame('Economy', $options["carrier:{$carrier}:economy"] ?? null);
        self::assertSame('13', array_column($this->rows($summary['shipping_options']), 'price', 'id')["carrier:{$carrier}:economy"] ?? null);

        $placed = $this->request('POST', '/checkout', ['shipping_option' => "carrier:{$carrier}:express"] + $this->order('cash_on_delivery'), nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $receipt = $this->data($this->request('GET', "/receipts/{$this->key($this->data($placed))}"));
        self::assertSame(['Speedy – Express', '26'], [$receipt['shipping'], $receipt['shipping_total']]);

        // To an office: the customer chooses one, and the shop books the shipment there.
        unset($_COOKIE[CartCookie::NAME]);
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));
        $office = "carrier:{$carrier}:office";
        $points = $this->data($this->request('POST', '/checkout/summary', ['shipping_country' => 'BG', 'shipping_option' => $office], nonce: true))['pickup_points'];
        self::assertSame(['SOF-1', 'SOF-2'], array_column($this->rows($points), 'id'));
        self::assertSame(422, $this->request('POST', '/checkout', ['shipping_option' => $office] + $this->order('cash_on_delivery'), nonce: true)->get_status(), 'no office chosen');
        $toOffice = $this->data($this->request('POST', '/checkout', ['shipping_option' => $office, 'pickup_point' => 'SOF-1'] + $this->order('cash_on_delivery'), nonce: true));
        self::assertIsInt($toOffice['number']);

        $this->actAs('administrator');
        self::assertTrue($this->data($this->request('GET', "/orders/{$toOffice['number']}/shipments"))['bookable']);
        $booked = $this->request('POST', "/orders/{$toOffice['number']}/shipments", ['parcels' => 1], nonce: true);
        self::assertSame(201, $booked->get_status(), (string) wp_json_encode($booked->get_data()));
        $shipment = $this->data($booked);
        self::assertIsInt($shipment['id']);
        self::assertIsString($shipment['number']);
        self::assertSame(['booked', 'https://track.test/' . $shipment['number']], [$shipment['status'], $shipment['tracking_url']]);
        $label = $this->data($this->request('GET', "/shipments/{$shipment['id']}/label"));
        self::assertIsString($label['content']);
        self::assertStringStartsWith('%PDF', (string) base64_decode($label['content'], true));
        self::assertSame('cancelled', $this->data($this->request('POST', "/shipments/{$shipment['id']}/cancel", nonce: true))['status']);
        self::assertSame('Office Vitosha, 12 Vitosha Blvd, 1000 Sofia', $this->data($this->request('GET', "/orders/{$toOffice['number']}"))['pickup_point']);
    }

    /**
     * Plays PayPal for wp_remote_* calls to its API.
     *
     * @param false|array<string, mixed>|\WP_Error $preempt
     * @param array<string, mixed>                 $args
     *
     * @return false|array<string, mixed>
     */
    public function payPal(false|array|\WP_Error $preempt, array $args, string $url): false|array
    {
        if (!str_starts_with($url, 'https://api-m.sandbox.paypal.com/')) {
            return $preempt instanceof \WP_Error ? false : $preempt;
        }
        $body = \is_string($args['body'] ?? null) ? $args['body'] : '';
        $headers = \is_array($args['headers'] ?? null) ? $args['headers'] : [];
        $this->payPalCalls[] = ['method' => \is_string($args['method'] ?? null) ? $args['method'] : 'GET', 'url' => $url, 'body' => $body, 'headers' => $headers];
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($this->expiredToken !== null && ($headers['Authorization'] ?? null) === "Bearer {$this->expiredToken}") {
            return ['headers' => [], 'body' => (string) wp_json_encode(['error' => 'invalid_token']), 'response' => ['code' => 401, 'message' => ''], 'cookies' => [], 'filename' => null];
        }
        if ($this->refusesDetails && $path === '/v2/checkout/orders' && str_contains($body, '"items"')) {
            return ['headers' => [], 'body' => (string) wp_json_encode(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'POSTAL_CODE_REQUIRED']]]), 'response' => ['code' => 422, 'message' => ''], 'cookies' => [], 'filename' => null];
        }
        $answer = match (true) {
            $path === '/v1/oauth2/token' => ['access_token' => 'TOKEN-' . ++$this->tokens, 'expires_in' => 3600],
            $path === '/v2/checkout/orders' => ['id' => 'PAY-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAY-1']]],
            $path === '/v2/checkout/orders/PAY-1/capture' => ['id' => 'PAY-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [
                ['id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '38.33']],
            ]]]]],
            $path === '/v2/payments/captures/CAP-1/refund' => ['id' => 'RF-1', 'status' => 'COMPLETED'],
            $path === '/v1/notifications/verify-webhook-signature' => ['verification_status' => str_contains($body, '"transmission_sig":"genuine"') ? 'SUCCESS' : 'FAILURE'],
            default => null,
        };

        return ['headers' => [], 'body' => (string) wp_json_encode($answer ?? ['name' => 'NOT_FOUND']), 'response' => ['code' => $answer === null ? 404 : 200, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    /**
     * @param array<string, string> $details
     *
     * @return list<mixed> the names of the rates the checkout summary offers
     */
    private function shippingNames(array $details): array
    {
        return array_column($this->rows($this->data($this->request('POST', '/checkout/summary', $details, nonce: true))['shipping_options']), 'name');
    }

    private function carrierOf(int $rate): int
    {
        foreach ($this->rows($this->data($this->request('GET', '/carriers'))) as $carrier) {
            if (\in_array($rate, array_column($this->rows($carrier['rates']), 'id'), true)) {
                self::assertIsInt($carrier['id']);

                return $carrier['id'];
            }
        }
        self::fail("No carrier has rate {$rate}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $payment): array
    {
        return [
            'email' => 'ada@example.com',
            'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'],
            'shipping_option' => 'rate:' . $this->rate,
            'payment' => $payment,
        ];
    }

    private function keepCookie(\WP_REST_Response $response): void
    {
        $header = $response->get_headers()['Set-Cookie'] ?? '';
        self::assertIsString($header);
        self::assertMatchesRegularExpression('/^orillacart_cart=[a-f0-9]{64}; Path=\/; Max-Age=\d+; HttpOnly; SameSite=Lax/', $header);
        $_COOKIE[CartCookie::NAME] = substr($header, \strlen(CartCookie::NAME) + 1, 64);
    }

    private function stock(): StockService
    {
        $stock = self::kernel()->container()->get(StockService::class);
        self::assertInstanceOf(StockService::class, $stock);

        return $stock;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    private function post(string $title): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;

        return $id;
    }

    private function id(\WP_REST_Response $response): int
    {
        $id = $this->data($response)['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    private function tableRows(string $table): string
    {
        $wpdb = $this->wpdb();
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_{$table}");
        self::assertIsString($count);

        return $count;
    }

    /**
     * @param array<array-key, mixed> $order
     */
    private function key(array $order): string
    {
        self::assertIsString($order['receipt_url']);
        parse_str((string) parse_url($order['receipt_url'], \PHP_URL_QUERY), $query);
        self::assertIsString($query['orillacart_order'] ?? null);

        return $query['orillacart_order'];
    }

    /**
     * The value at a path in decoded JSON; the test fails when the path is not there.
     */
    private static function at(mixed $data, int|string ...$path): mixed
    {
        foreach ($path as $step) {
            self::assertIsArray($data);
            self::assertArrayHasKey($step, $data);
            $data = $data[$step];
        }

        return $data;
    }

    private function wpdb(): \wpdb
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);

        return $wpdb;
    }

    private function clean(): void
    {
        $wpdb = $this->wpdb();
        foreach (['abandoned_carts', 'invoices', 'refunds', 'coupon_redemptions', 'coupons', 'gift_card_entries', 'gift_cards', 'consignments', 'payments', 'payment_methods', 'order_lines', 'orders', 'cart_lines', 'carts', 'shipping_rates', 'carriers', 'shipping_classes', 'stock_levels', 'stockrooms', 'variations', 'products'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
        foreach (array_keys(wp_load_alloptions()) as $option) {
            if (str_starts_with((string) $option, '_transient_orillacart_paypal_')) {
                delete_transient(substr((string) $option, \strlen('_transient_')));
            }
        }
    }
}
