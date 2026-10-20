<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Exchange\Application\OrdersExport;
use OrillaCart\Exchange\Infrastructure\WordPress\OptionExportChoices;
use OrillaCart\Ordering\Application\Dto\OrderQuery;

/**
 * Exports over the REST routes and the download: the columns the shop manager chooses, an add-on's among them,
 * and the orders the list's filters find.
 */
final class ExportsRestTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        delete_option(OptionExportChoices::OPTION);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[CartCookie::NAME]);
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        $this->clean();
        delete_option(OptionExportChoices::OPTION);
        parent::tearDown();
    }

    public function testTheShopManagerChoosesTheOrderColumnsAndExportsWhatTheListFinds(): void
    {
        wp_set_current_user(0);
        self::assertSame(401, $this->request('GET', '/exports/orders/columns')->get_status());
        $this->actAs('administrator');
        $lamp = $this->product();
        $this->request('PUT', '/payment-methods/bank_transfer', ['name' => 'Bank transfer', 'active' => true], nonce: true);
        $number = $this->order($lamp);
        $this->actAs('administrator');

        $columns = $this->data($this->request('GET', '/exports/orders/columns'));
        $warehouse = array_values(array_filter($columns, static fn(mixed $column): bool => \is_array($column) && $column['id'] === 'test_warehouse'))[0] ?? null;
        self::assertSame(['label' => 'Warehouse', 'add_on' => true, 'chosen' => true], \is_array($warehouse) ? array_intersect_key($warehouse, ['label' => 1, 'add_on' => 1, 'chosen' => 1]) : null, 'the add-on\'s column, listed beside the shop\'s');
        $chosen = $this->request('PUT', '/exports/orders/columns', ['columns' => ['test_warehouse', 'number', 'email']], nonce: true);
        self::assertSame(200, $chosen->get_status(), (string) wp_json_encode($chosen->get_data()));
        self::assertSame(422, $this->request('PUT', '/exports/orders/columns', ['columns' => ['nothing']], nonce: true)->get_status());

        $export = self::kernel()->container()->get(OrdersExport::class);
        self::assertInstanceOf(OrdersExport::class, $export);
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $export->export(new OrderQuery(status: 'on_hold'), new CsvFile($stream));
        rewind($stream);
        $lines = explode("\n", trim(substr((string) stream_get_contents($stream), 3)));
        self::assertSame(['Warehouse,Order,Email', "W-{$number},{$number},ada@example.com"], $lines);
    }

    private function product(): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Lamp', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        $saved = $this->request('PUT', "/products/{$id}", ['price' => '30', 'type' => 'virtual'], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        return $id;
    }

    private function order(int $product): int
    {
        wp_set_current_user(0);
        unset($_COOKIE[CartCookie::NAME]);
        $added = $this->request('POST', '/cart/items', ['product_id' => $product, 'quantity' => 1], nonce: true);
        $cookie = $added->get_headers()['Set-Cookie'] ?? null;
        if (\is_string($cookie)) {
            $_COOKIE[CartCookie::NAME] = substr($cookie, \strlen(CartCookie::NAME) + 1, 64);
        }
        $placed = $this->request('POST', '/checkout', [
            'email' => 'ada@example.com',
            'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'],
            'payment' => 'bank_transfer',
        ], nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $number = $this->data($placed)['number'];
        self::assertIsInt($number);

        return $number;
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['pending_accounts', 'invoices', 'price_rules', 'customer_group_members', 'customer_groups', 'download_grants', 'order_notes', 'refunds', 'payments', 'payment_methods', 'order_lines', 'orders', 'cart_lines', 'carts', 'product_downloads', 'stock_levels', 'stockrooms', 'variations', 'products'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
