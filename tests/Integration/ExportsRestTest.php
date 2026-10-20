<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Exchange\Application\OrdersExport;
use OrillaCart\Exchange\Application\ProductImports;
use OrillaCart\Exchange\Application\ProductsExport;
use OrillaCart\Exchange\Infrastructure\WordPress\OptionExportChoices;
use OrillaCart\Jobs\Application\JobRunner;
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

    public function testTheProductsFileHasTheProductsPostsAndTermsByColumnIds(): void
    {
        $this->actAs('administrator');
        $lamp = $this->product();
        wp_update_post(['ID' => $lamp, 'post_excerpt' => 'Bright', 'post_status' => 'draft']);
        $parent = wp_insert_term('Lighting', ProductCategoryTaxonomy::NAME);
        self::assertIsArray($parent);
        $child = wp_insert_term('Desk lamps', ProductCategoryTaxonomy::NAME, ['parent' => max(0, (int) $parent['term_id'])]);
        self::assertIsArray($child);
        wp_set_object_terms($lamp, [(int) $child['term_id']], ProductCategoryTaxonomy::NAME);

        $chosen = $this->request('PUT', '/exports/products/columns', ['columns' => ['id', 'name', 'status', 'price', 'categories', 'short_description']], nonce: true);
        self::assertSame(200, $chosen->get_status(), (string) wp_json_encode($chosen->get_data()));
        $export = self::kernel()->container()->get(ProductsExport::class);
        self::assertInstanceOf(ProductsExport::class, $export);
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $export->export(new CsvFile($stream, headerIds: true));
        rewind($stream);

        self::assertSame(['id,name,status,price,categories,short_description', "{$lamp},Lamp,draft,30,\"Lighting > Desk lamps\",Bright"], explode("\n", trim(substr((string) stream_get_contents($stream), 3))));
    }

    public function testAnUploadedFileIsImportedByItsJobIntoWordPress(): void
    {
        $this->actAs('administrator');
        $lamp = $this->product();
        $file = tempnam(sys_get_temp_dir(), 'import');
        self::assertIsString($file);
        file_put_contents($file, "\u{FEFF}id,name,price,status,categories,short_description\n{$lamp},Brass lamp,35,draft,Lighting > Desk lamps,Bright\n,Bulb,3,,,\n");
        $upload = new \WP_REST_Request('POST', self::NAMESPACE . '/imports/products');
        $upload->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $upload->set_file_params(['file' => ['name' => 'products.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => max(0, (int) filesize($file))]]);
        $uploaded = rest_do_request($upload);
        self::assertSame(201, $uploaded->get_status(), (string) wp_json_encode($uploaded->get_data()));
        $import = $this->data($uploaded);
        self::assertSame(['ready', 2], [$import['status'], $import['rows']]);
        self::assertIsInt($import['id']);

        $started = $this->request('POST', "/imports/products/{$import['id']}/start", [], nonce: true);
        self::assertSame(200, $started->get_status(), (string) wp_json_encode($started->get_data()));
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['imports'], 10, 'test')->count() > 0);

        // Read anew: the requests' entity manager still holds the import as the start left it.
        $imports = self::kernel()->container()->get(ProductImports::class);
        self::assertInstanceOf(ProductImports::class, $imports);
        $done = $imports->latest();
        self::assertNotNull($done);
        self::assertSame(['done', 1, 1, 0], [$done->status, $done->created, $done->updated, $done->failed], (string) wp_json_encode($done));
        $post = get_post($lamp);
        self::assertInstanceOf(\WP_Post::class, $post);
        self::assertSame(['Brass lamp', 'draft', 'Bright'], [$post->post_title, $post->post_status, $post->post_excerpt]);
        $categories = wp_get_object_terms($lamp, ProductCategoryTaxonomy::NAME);
        self::assertIsArray($categories);
        self::assertSame(['Desk lamps'], wp_list_pluck($categories, 'name'));
        $products = self::kernel()->container()->get(ProductService::class);
        self::assertInstanceOf(ProductService::class, $products);
        self::assertSame('35', $products->product($lamp)->price);
        $bulb = get_posts(['post_type' => ProductPostType::NAME, 'title' => 'Bulb', 'post_status' => 'any', 'fields' => 'ids']);
        self::assertCount(1, $bulb);
        $this->posts[] = (int) $bulb[0];
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
        // What a test that failed left: the export reads the whole catalog, and the import finds by name.
        foreach (get_posts(['post_type' => ProductPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $left) {
            wp_delete_post((int) $left, true);
        }
        $terms = get_terms(['taxonomy' => ProductCategoryTaxonomy::NAME, 'hide_empty' => false, 'fields' => 'ids']);
        foreach (\is_array($terms) ? $terms : [] as $term) {
            wp_delete_term((int) $term, ProductCategoryTaxonomy::NAME);
        }
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['product_imports', 'pending_accounts', 'invoices', 'price_rules', 'customer_group_members', 'customer_groups', 'download_grants', 'order_notes', 'refunds', 'payments', 'payment_methods', 'order_lines', 'orders', 'cart_lines', 'carts', 'product_downloads', 'stock_levels', 'stockrooms', 'variations', 'products'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
