<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;

/**
 * The store through the REST routes: the shop sets up shipping and payment, a visitor fills a cart (kept by a
 * cookie), checks out and pays offline or at PayPal. PayPal's API is played by intercepting WordPress HTTP calls.
 */
final class CheckoutRestTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    /** @var list<array{method: string, url: string, body: string}> */
    private array $payPalCalls = [];
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
        self::assertSame(['cash_on_delivery', 'bank_transfer', 'paypal', 'cheque'], array_column($methods, 'gateway'));
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

        self::assertSame(['ada@example.com', 'wordpress@example.com'], array_column($this->sentMail(), 'to'));
        self::assertStringContainsString('We received your order', $this->sentMail()[0]['subject'] ?? '');
        self::assertStringContainsString('Have the cash ready.', $this->sentMail()[0]['message'] ?? '');

        $receipt = $this->request('GET', "/receipts/{$this->key($order)}");
        self::assertSame(200, $receipt->get_status());
        self::assertSame(['Cash on delivery', 'Have the cash ready.', 'Courier – Bulgaria'], [$this->data($receipt)['payment_name'], $this->data($receipt)['payment_note'], $this->data($receipt)['shipping']]);
        self::assertSame(404, $this->request('GET', '/receipts/' . str_repeat('0', 32))->get_status());
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

    public function testAVisitorPaysWithPayPal(): void
    {
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->product], nonce: true));

        $order = $this->data($this->request('POST', '/checkout', $this->order('paypal'), nonce: true));
        self::assertSame(['pending_payment', 'https://www.sandbox.paypal.com/checkoutnow?token=PAY-1'], [$order['status'], $order['redirect_url']]);
        $creates = array_values(array_filter($this->payPalCalls, static fn(array $call): bool => $call['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'));
        self::assertCount(1, $creates);
        $create = $creates[0];
        $body = json_decode($create['body'], true);
        self::assertSame(['currency_code' => 'EUR', 'value' => '38.33'], self::at($body, 'purchase_units', 0, 'amount'));
        self::assertSame(rest_url('orillacart/v1/payments/paypal/return'), self::at($body, 'payment_source', 'paypal', 'experience_context', 'return_url'));

        $return = new \WP_REST_Request('GET', self::NAMESPACE . '/payments/paypal/return');
        $return->set_query_params(['token' => 'PAY-1', 'PayerID' => 'PAYER']);
        $back = rest_do_request($return);
        self::assertSame(303, $back->get_status());
        self::assertSame($order['receipt_url'], $back->get_headers()['Location']);
        self::assertSame('processing', $this->data($this->request('GET', "/receipts/{$this->key($order)}"))['status']);
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

        self::assertSame(422, $response->get_status());
        self::assertSame('pending_payment', $this->data($this->request('GET', "/receipts/{$this->key($order)}"))['status']);

        $forged->set_header('PayPal-Transmission-Sig', 'genuine');
        self::assertSame(200, rest_do_request($forged)->get_status());
        self::assertSame('processing', $this->data($this->request('GET', "/receipts/{$this->key($order)}"))['status']);
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
        $this->payPalCalls[] = ['method' => \is_string($args['method'] ?? null) ? $args['method'] : 'GET', 'url' => $url, 'body' => $body];
        $path = (string) parse_url($url, PHP_URL_PATH);
        $answer = match (true) {
            $path === '/v1/oauth2/token' => ['access_token' => 'TOKEN', 'expires_in' => 3600],
            $path === '/v2/checkout/orders' => ['id' => 'PAY-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAY-1']]],
            $path === '/v2/checkout/orders/PAY-1/capture' => ['id' => 'PAY-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [
                ['id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '38.33']],
            ]]]]],
            $path === '/v1/notifications/verify-webhook-signature' => ['verification_status' => str_contains($body, '"transmission_sig":"genuine"') ? 'SUCCESS' : 'FAILURE'],
            default => null,
        };

        return ['headers' => [], 'body' => (string) wp_json_encode($answer ?? ['name' => 'NOT_FOUND']), 'response' => ['code' => $answer === null ? 404 : 200, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $payment): array
    {
        return [
            'email' => 'ada@example.com',
            'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'],
            'rate_id' => $this->rate,
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
        self::assertIsString($order['key']);

        return $order['key'];
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
        foreach (['payments', 'payment_methods', 'order_lines', 'orders', 'cart_lines', 'carts', 'shipping_rates', 'carriers', 'shipping_classes', 'stock_levels', 'stockrooms', 'variations', 'products'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
        foreach (array_keys(wp_load_alloptions()) as $option) {
            if (str_starts_with((string) $option, '_transient_orillacart_paypal_')) {
                delete_transient(substr((string) $option, \strlen('_transient_')));
            }
        }
    }
}
