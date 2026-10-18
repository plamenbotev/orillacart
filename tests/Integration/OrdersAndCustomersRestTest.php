<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Customers\Infrastructure\WordPress\WordPressAccounts;
use OrillaCart\Ordering\Application\Downloads;

/**
 * The shop works its orders and customers through the REST routes: statuses, notes and emails, editing an order
 * on hold, customer accounts made at checkout, and downloads of a paid order.
 */
final class OrdersAndCustomersRestTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    /** @var list<int> */
    private array $users = [];
    private int $lamp;
    private int $guide;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        unset($_COOKIE[CartCookie::NAME]);
        $this->actAs('administrator');
        $this->lamp = $this->product('Lamp', ['price' => '30', 'type' => 'virtual']);
        $this->guide = $this->product('Guide', ['price' => '8', 'type' => 'virtual', 'download_limit' => 2]);
        $stockroom = $this->id($this->request('POST', '/stockrooms', ['name' => 'Sofia'], nonce: true));
        $this->request('PUT', "/stock/product/{$this->lamp}", ['levels' => [['stockroom_id' => $stockroom, 'quantity' => 3]]], nonce: true);
        $this->request('PUT', '/payment-methods/bank_transfer', ['name' => 'Bank transfer', 'active' => true], nonce: true);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[CartCookie::NAME]);
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        foreach ($this->users as $user) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user);
        }
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyShopManagersWorkOrdersAndOnlyCustomersSeeTheirAccount(): void
    {
        $shop = [['GET', '/orders'], ['GET', '/orders/1'], ['PUT', '/orders/1/status'], ['PUT', '/orders/1/contact'], ['POST', '/orders/1/notes'],
            ['PUT', '/orders/1/items'], ['POST', '/orders/1/emails'], ['DELETE', '/orders/1'], ['GET', '/customers'], ['GET', '/customers/1'],
            ['PUT', '/customers/1/addresses'], ['GET', '/customer-settings'], ['PUT', '/customer-settings'], ['GET', '/email-settings'],
            ['PUT', '/email-settings'], ['POST', '/products/1/downloads'], ['DELETE', '/products/1/downloads/1'], ['GET', '/download-storage'],
            ['PUT', '/orders/1/customer'], ['GET', '/orders/1/shipping-options'], ['PUT', '/orders/1/downloads/1'], ['POST', '/orders'], ['POST', '/orders/quote'],
            ['GET', '/orders/1/shipments'], ['POST', '/orders/1/shipments'], ['GET', '/shipments/1/label'], ['POST', '/shipments/1/cancel'], ['GET', '/shipping-methods'], ['GET', '/orders/1/pickup-points'],
            ['GET', '/search-filters'], ['POST', '/search-filters'], ['PUT', '/search-filters/1'], ['DELETE', '/search-filters/1'], ['GET', '/search-filters/attributes']];
        $account = [['GET', '/account'], ['PUT', '/account/addresses'], ['GET', '/account/orders'], ['GET', '/account/orders/1'], ['GET', '/account/downloads']];
        foreach ([...$shop, ...$account] as [$method, $route]) {
            self::assertSame(401, $this->request($method, $route)->get_status(), "visitor: {$method} {$route}");
        }
        $this->actAs('subscriber');
        foreach ($shop as [$method, $route]) {
            self::assertSame(403, $this->request($method, $route, nonce: true)->get_status(), "subscriber: {$method} {$route}");
        }
        self::assertSame(200, $this->request('GET', '/account/orders')->get_status());
    }

    public function testTheShopMovesAnOrderAlongAndTheCustomerHearsOfIt(): void
    {
        $order = $this->order([[$this->lamp, 1]]);
        $this->actAs('administrator');

        $list = $this->data($this->request('GET', '/orders?status=on_hold&search=ada'));
        self::assertSame([[$order['number']], 1], [array_column($this->rows($list['orders']), 'number'), $list['total']]);

        $this->mails = [];
        $changed = $this->request('PUT', "/orders/{$order['number']}/status", ['status' => 'processing', 'note' => 'Paid at the bank.'], nonce: true);
        self::assertSame(200, $changed->get_status(), (string) wp_json_encode($changed->get_data()));
        self::assertSame(['shipped', 'completed', 'cancelled', 'refunded'], $this->data($changed)['next_statuses']);
        $this->request('POST', "/orders/{$order['number']}/notes", ['text' => 'It leaves tomorrow.', 'for_customer' => true], nonce: true);
        $sent = [];
        foreach ($this->sentMail() as $mail) {
            $sent[] = $mail['to'] . ': ' . preg_replace('/^\[[^\]]+\] /', '', $mail['subject']);
        }
        self::assertSame(["ada@example.com: Your order #{$order['number']} is confirmed", "ada@example.com: A note about your order #{$order['number']}"], $sent);

        $bad = $this->request('PUT', "/orders/{$order['number']}/status", ['status' => 'pending_payment'], nonce: true);
        self::assertSame(422, $bad->get_status());
        self::assertSame(422, $this->request('DELETE', "/orders/{$order['number']}", nonce: true)->get_status(), 'a paid order is cancelled or refunded first');

        $receipt = $this->data($this->request('GET', "/receipts/{$order['key']}"));
        self::assertSame(['It leaves tomorrow.'], array_column($this->rows($receipt['notes']), 'text'), 'the customer sees only their notes');
    }

    public function testTheShopTakesAnOrderItselfAndDeletesItWithItsStockBack(): void
    {
        $this->actAs('administrator');
        $billing = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'];
        $items = [['product_id' => $this->lamp, 'quantity' => 2, 'unit_price' => '25']];

        $quote = $this->request('POST', '/orders/quote', ['items' => $items, 'billing_country' => 'BG'], nonce: true);
        self::assertSame(200, $quote->get_status(), (string) wp_json_encode($quote->get_data()));
        self::assertSame(['50', ['bank_transfer']], [$this->data($quote)['total'], array_column($this->rows($this->data($quote)['payment_options']), 'gateway')]);

        $created = $this->request('POST', '/orders', ['email' => 'ada@example.com', 'billing' => $billing, 'items' => $items, 'payment' => 'bank_transfer'], nonce: true);
        self::assertSame(201, $created->get_status(), (string) wp_json_encode($created->get_data()));
        $order = $this->data($created);
        self::assertIsInt($order['number']);
        self::assertSame(['on_hold', '50', 'Bank transfer'], [$order['status'], $order['total'], $order['payment_name']]);
        self::assertSame(1, $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available']);

        self::assertSame(422, $this->request('POST', '/orders', ['email' => 'ada@example.com', 'billing' => $billing, 'items' => []], nonce: true)->get_status(), 'an order has items');
        $deleted = $this->request('DELETE', "/orders/{$order['number']}", nonce: true);
        self::assertSame(204, $deleted->get_status(), (string) wp_json_encode($deleted->get_data()));
        self::assertSame(3, $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available'], 'the order on hold gave its stock back');
    }

    public function testEditingAnOrderThatRunsOutOfStockChangesNothing(): void
    {
        $order = $this->order([[$this->lamp, 2]]);
        $this->actAs('administrator');

        $edited = $this->request('PUT', "/orders/{$order['number']}/items", ['items' => [['product_id' => $this->lamp, 'quantity' => 3, 'unit_price' => '25']]], nonce: true);
        self::assertSame(200, $edited->get_status(), (string) wp_json_encode($edited->get_data()));
        self::assertSame('75', $this->data($edited)['total']);

        $tooMany = $this->request('PUT', "/orders/{$order['number']}/items", ['items' => [['product_id' => $this->lamp, 'quantity' => 4]]], nonce: true);
        self::assertSame(422, $tooMany->get_status());
        $after = $this->data($this->request('GET', "/orders/{$order['number']}"));
        self::assertSame(['75', 3], [$after['total'], $this->rows($after['lines'])[0]['quantity']], 'the order and its stock are as before');
        self::assertSame(0, $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available']);
    }

    public function testAVisitorMakesAnAccountAndDownloadsWhatTheyBought(): void
    {
        $this->actAs('administrator');
        $file = tempnam(sys_get_temp_dir(), 'guide');
        self::assertIsString($file);
        file_put_contents($file, '%PDF guide');
        $upload = new \WP_REST_Request('POST', self::NAMESPACE . "/products/{$this->guide}/downloads");
        $upload->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $upload->set_file_params(['file' => ['name' => 'guide.pdf', 'type' => 'application/pdf', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => 10]]);
        $uploaded = rest_do_request($upload);
        self::assertSame(201, $uploaded->get_status(), (string) wp_json_encode($uploaded->get_data()));
        self::assertSame([], get_posts(['post_type' => 'attachment', 'numberposts' => -1]), 'not a media library item');
        $storage = $this->data($this->request('GET', '/download-storage?fresh=1'));
        self::assertNotTrue($storage['reachable'], 'the web server must not hand the files out by itself');
        wp_set_current_user(0);

        $order = $this->order([[$this->guide, 1]], ['create_account' => true, 'password' => 'correct horse battery']);
        $user = get_user_by('email', 'ada@example.com');
        self::assertInstanceOf(\WP_User::class, $user);
        $this->users[] = $user->ID;
        self::assertSame([WordPressAccounts::ROLE], $user->roles);
        self::assertSame($user->ID, get_current_user_id(), 'logged in after ordering');
        self::assertSame('Sofia', $this->rows([$this->data($this->request('GET', '/account'))['billing']])[0]['city']);
        self::assertSame([$order['number']], array_column($this->rows($this->data($this->request('GET', '/account/orders'))['orders']), 'number'));
        self::assertSame([], $this->data($this->request('GET', '/account/downloads')), 'not paid yet');

        $this->actAs('administrator');
        $this->request('PUT', "/orders/{$order['number']}/status", ['status' => 'processing'], nonce: true);
        wp_set_current_user($user->ID);
        $downloads = $this->rows($this->data($this->request('GET', '/account/downloads')));
        self::assertSame(['guide.pdf', 2], [$downloads[0]['name'], $downloads[0]['remaining']]);

        $files = self::kernel()->container()->get(Downloads::class);
        self::assertInstanceOf(Downloads::class, $files);
        $url = $downloads[0]['url'];
        self::assertIsString($url);
        $opened = $files->open(substr($url, -64));
        self::assertSame('%PDF guide', file_get_contents($opened->path));
        self::assertStringStartsWith(rtrim($this->text($storage['directory']), '/') . '/', $opened->path);
        self::assertStringStartsNotWith(rtrim(ABSPATH, '/') . '/wp-content/uploads/' . basename($opened->path), $opened->path);

        $this->actAs('administrator');
        self::assertSame([], $this->data($this->request('GET', "/orders/{$order['number']}/shipping-options")), 'nothing to ship');
        $grant = $this->rows($this->data($this->request('GET', "/orders/{$order['number']}"))['downloads'])[0]['id'];
        self::assertIsInt($grant);
        $more = $this->request('PUT', "/orders/{$order['number']}/downloads/{$grant}", ['remaining' => 5, 'expires_at' => '2030-01-31'], nonce: true);
        self::assertSame(200, $more->get_status(), (string) wp_json_encode($more->get_data()));
        self::assertSame(422, $this->request('PUT', "/orders/{$order['number']}/downloads/{$grant}", ['remaining' => -1], nonce: true)->get_status());
        self::assertSame(404, $this->request('PUT', "/orders/{$order['number']}/downloads/999999", ['remaining' => 1], nonce: true)->get_status());

        $guest = $this->request('PUT', "/orders/{$order['number']}/customer", ['customer_id' => null], nonce: true);
        self::assertSame(200, $guest->get_status(), (string) wp_json_encode($guest->get_data()));
        self::assertNull($this->data($guest)['customer_id']);
        self::assertSame(404, $this->request('PUT', "/orders/{$order['number']}/customer", ['customer_id' => 999999], nonce: true)->get_status(), 'no such account');
        wp_set_current_user($user->ID);
        self::assertSame([], $this->data($this->request('GET', '/account/orders'))['orders'], 'a guest\'s order now');

        $this->actAs('administrator');
        $this->request('PUT', "/orders/{$order['number']}/customer", ['customer_id' => $user->ID], nonce: true);
        wp_set_current_user($user->ID);
        $downloads = $this->rows($this->data($this->request('GET', '/account/downloads')));
        self::assertSame([5, '2030-01-31'], [$downloads[0]['remaining'], substr($this->text($downloads[0]['expires_at']), 0, 10)]);
    }

    /**
     * @param list<array{int, int}> $lines product id, quantity
     * @param array<string, mixed>  $extra
     *
     * @return array{number: int, key: string}
     */
    private function order(array $lines, array $extra = []): array
    {
        unset($_COOKIE[CartCookie::NAME]);
        foreach ($lines as [$product, $quantity]) {
            $added = $this->request('POST', '/cart/items', ['product_id' => $product, 'quantity' => $quantity], nonce: true);
            $cookie = $added->get_headers()['Set-Cookie'] ?? null;
            if (\is_string($cookie)) {
                $_COOKIE[CartCookie::NAME] = substr($cookie, \strlen(CartCookie::NAME) + 1, 64);
            }
        }
        $placed = $this->request('POST', '/checkout', [
            'email' => 'ada@example.com',
            'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'],
            'payment' => 'bank_transfer',
        ] + $extra, nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $order = $this->data($placed);
        self::assertIsInt($order['number']);
        self::assertIsString($order['key']);

        return ['number' => $order['number'], 'key' => $order['key']];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function product(string $name, array $details): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $name, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        $saved = $this->request('PUT', "/products/{$id}", $details, nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        return $id;
    }

    private function id(\WP_REST_Response $response): int
    {
        $id = $this->data($response)['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    private function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
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

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['download_grants', 'order_notes', 'payments', 'payment_methods', 'order_lines', 'orders', 'cart_lines', 'carts', 'product_downloads', 'stock_levels', 'stockrooms', 'variations', 'products'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
