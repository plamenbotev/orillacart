<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;

/**
 * Products, attribute sets, variations and stock through the REST routes, with product posts in WordPress and
 * the rest in the plugin's tables.
 */
final class CatalogRestTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyShopManagersReachTheCatalogAndStockRoutes(): void
    {
        $routes = [['GET', '/products/1'], ['PUT', '/products/1'], ['POST', '/products/1/variations'], ['PUT', '/products/1/variations/1'],
            ['DELETE', '/products/1/variations/1'], ['GET', '/attribute-sets'], ['POST', '/attribute-sets'], ['GET', '/attribute-sets/1'],
            ['PUT', '/attribute-sets/1'], ['DELETE', '/attribute-sets/1'], ['GET', '/stockrooms'], ['POST', '/stockrooms'],
            ['PUT', '/stockrooms/1'], ['DELETE', '/stockrooms/1'], ['GET', '/stock/product/1'], ['PUT', '/stock/product/1']];

        foreach ($routes as [$method, $route]) {
            self::assertSame(401, $this->request($method, $route)->get_status(), "visitor: {$method} {$route}");
        }
        $this->actAs('editor');
        foreach ($routes as [$method, $route]) {
            self::assertSame(403, $this->request($method, $route, nonce: true)->get_status(), "editor: {$method} {$route}");
        }
    }

    public function testProductsArePostsThatOnlyShopManagersEdit(): void
    {
        self::assertTrue(post_type_exists(ProductPostType::NAME));
        foreach ([ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME, ProductTagTaxonomy::NAME] as $taxonomy) {
            self::assertTrue(taxonomy_exists($taxonomy), $taxonomy);
        }
        $post = $this->post('Hoodie');

        $manageCategories = get_taxonomy(ProductCategoryTaxonomy::NAME)->cap->manage_terms ?? null;
        $publish = get_post_type_object(ProductPostType::NAME)->cap->publish_posts ?? null;
        self::assertIsString($manageCategories);
        self::assertIsString($publish);

        $this->actAs('editor');
        self::assertFalse(current_user_can('edit_post', $post));
        self::assertFalse(current_user_can($publish));
        self::assertFalse(current_user_can($manageCategories));
        $this->actAs('administrator');
        self::assertTrue(current_user_can('edit_post', $post));
        self::assertTrue(current_user_can($publish));
        self::assertTrue(current_user_can($manageCategories));
    }

    public function testAShopManagerSetsUpAProductWithVariationsAndStock(): void
    {
        $this->actAs('administrator');
        $post = $this->post('Hoodie');

        $unsaved = $this->data($this->request('GET', "/products/{$post}"));
        self::assertSame([$post, 'Hoodie', false, '0'], [$unsaved['id'], $unsaved['name'], $unsaved['saved'], $unsaved['price']]);

        $created = $this->request('POST', '/attribute-sets', ['name' => 'Clothing', 'attributes' => [
            ['name' => 'Size', 'required' => true, 'options' => [['name' => 'S'], ['name' => 'M', 'adjustment' => '2']]],
        ]], nonce: true);
        self::assertSame(201, $created->get_status());
        /** @var array{id: int, attributes: list<array{options: list<array{id: int}>}>} $set */
        $set = $this->data($created);
        [$small, $medium] = array_column($set['attributes'][0]['options'], 'id');

        $saved = $this->request('PUT', "/products/{$post}", [
            'price' => '40', 'sku' => 'HOOD', 'weight' => '0.5', 'set_ids' => [$set['id']],
            'sale' => ['price' => '35', 'from' => '2026-01-01T00:00:00+00:00', 'until' => null],
            'attributes' => [['name' => 'Gift wrap', 'options' => [['name' => 'Yes', 'adjustment' => '3.5']]]],
        ], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        /** @var array{saved: bool, price: string, sku: string, weight: string, sale: array{price: string, from: string}, offered: list<array{name: string, options: list<array{id: int}>}>} $product */
        $product = $this->data($saved);
        self::assertSame([true, '40', 'HOOD', '0.5', '35', '2026-01-01T00:00:00+00:00'], [$product['saved'], $product['price'], $product['sku'], $product['weight'], $product['sale']['price'], $product['sale']['from']]);
        self::assertSame(['Size', 'Gift wrap'], array_column($product['offered'], 'name'));
        self::assertSame([$small, $medium], array_column($product['offered'][0]['options'], 'id'));

        $created = $this->request('POST', "/products/{$post}/variations", ['option_ids' => [$medium], 'price' => '42', 'sku' => 'HOOD-M'], nonce: true);
        self::assertSame(201, $created->get_status(), (string) wp_json_encode($created->get_data()));
        /** @var array{id: int, label: string, price: string, sku: string, stock_mode: string} $variation */
        $variation = $this->data($created);
        self::assertSame(['M', '42', 'HOOD-M', 'tracked'], [$variation['label'], $variation['price'], $variation['sku'], $variation['stock_mode']]);

        $duplicate = $this->request('POST', "/products/{$post}/variations", ['option_ids' => [$medium], 'price' => '1'], nonce: true);
        self::assertSame(422, $duplicate->get_status());
        self::assertSame('orillacart_duplicate_variation', $this->data($duplicate)['code']);

        $stockroom = $this->id($this->request('POST', '/stockrooms', ['name' => 'Sofia', 'min_delivery_days' => 1, 'max_delivery_days' => 2], nonce: true));
        $levels = $this->request('PUT', "/stock/variation/{$variation['id']}", ['levels' => [['stockroom_id' => $stockroom, 'quantity' => 4]]], nonce: true);
        self::assertSame(200, $levels->get_status(), (string) wp_json_encode($levels->get_data()));
        self::assertSame(['item' => "variation:{$variation['id']}", 'levels' => [['stockroom_id' => $stockroom, 'quantity' => 4]], 'available' => 4], $this->data($levels));

        // A new request, as the next page load would be.
        $quotes = self::kernel()->container()->get(ProductQuotes::class);
        self::assertInstanceOf(ProductQuotes::class, $quotes);
        $quote = $quotes->quote(new QuoteRequest($post, [$medium]));
        self::assertSame([$variation['id'], 'Hoodie – M', '42', 4, true], [$quote->variationId, $quote->name, $quote->unitPrice, $quote->available, $quote->forSale]);

        $inUse = $this->request('DELETE', "/attribute-sets/{$set['id']}", nonce: true);
        self::assertSame(422, $inUse->get_status());
        self::assertSame('orillacart_attribute_set_in_use', $this->data($inUse)['code']);

        self::assertSame(204, $this->request('DELETE', "/products/{$post}/variations/{$variation['id']}", nonce: true)->get_status());
        self::assertSame('0', $this->wpdb()->get_var($this->wpdb()->prepare('SELECT COUNT(*) FROM %i WHERE item_kind = %s', $this->wpdb()->prefix . 'orillacart_stock_levels', 'variation')), 'the variation\'s stock went with it');
        self::assertSame(['item' => "product:{$post}", 'levels' => [['stockroom_id' => $stockroom, 'quantity' => 0]], 'available' => 0], $this->data($this->request('GET', "/stock/product/{$post}")), 'the product, left without variations, counts its own stock from nothing');
        self::assertSame(204, $this->request('DELETE', "/attribute-sets/{$set['id']}", nonce: true)->get_status());
    }

    public function testInvalidProductsAreRefused(): void
    {
        $this->actAs('administrator');
        $post = $this->post('Mug');
        $other = $this->post('Cup');
        $this->request('PUT', "/products/{$other}", ['price' => '1', 'sku' => 'CUP'], nonce: true);

        $invalid = $this->request('PUT', "/products/{$post}", ['price' => '1,5', 'type' => 'digital', 'stock_mode' => 'maybe', 'weight' => '-1', 'attributes' => [['name' => '', 'options' => []]]], nonce: true);
        self::assertSame(422, $invalid->get_status());
        self::assertSame(['price', 'type', 'stock_mode', 'weight', 'attributes[0].name', 'attributes[0].options'], array_column($this->violations($invalid), 'field'));

        $taken = $this->request('PUT', "/products/{$post}", ['price' => '1', 'sku' => 'CUP'], nonce: true);
        self::assertSame(422, $taken->get_status());
        self::assertSame('orillacart_duplicate_sku', $this->data($taken)['code']);
        // The database holds each SKU once: changing one frees it for others.
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $claims = static fn(): array => $wpdb->get_col($wpdb->prepare('SELECT sku FROM %i ORDER BY sku', $wpdb->prefix . 'orillacart_skus'));
        self::assertContains('CUP', $claims());
        $this->request('PUT', "/products/{$other}", ['price' => '1', 'sku' => 'CUP-2'], nonce: true);
        $bowl = $this->post('Bowl');
        self::assertSame(200, $this->request('PUT', "/products/{$bowl}", ['price' => '1', 'sku' => 'CUP'], nonce: true)->get_status());
        self::assertSame(['CUP', 'CUP-2'], array_values(array_intersect($claims(), ['CUP', 'CUP-2'])));

        $page = wp_insert_post(['post_type' => 'page', 'post_title' => 'About', 'post_status' => 'publish']);
        self::assertIsInt($page);
        $this->posts[] = $page;
        self::assertSame(404, $this->request('GET', "/products/{$page}")->get_status(), 'not a product post');
        self::assertSame(404, $this->request('POST', "/products/{$post}/variations", ['option_ids' => [1], 'price' => '1'], nonce: true)->get_status(), 'never saved');
    }

    public function testDeletingAProductPostDeletesItsCommerceDataAndStock(): void
    {
        $this->actAs('administrator');
        $post = $this->post('Mug');
        $this->request('PUT', "/products/{$post}", ['price' => '10', 'attributes' => [['name' => 'Colour', 'options' => [['name' => 'Red']]]]], nonce: true);
        $stockroom = $this->id($this->request('POST', '/stockrooms', ['name' => 'Sofia'], nonce: true));
        $this->request('PUT', "/stock/product/{$post}", ['levels' => [['stockroom_id' => $stockroom, 'quantity' => 3]]], nonce: true);
        self::assertSame('1', $this->rows('products'));

        wp_trash_post($post);
        self::assertSame('1', $this->rows('products'), 'a trashed product can come back');
        wp_delete_post($post, true);

        foreach (['products', 'attributes', 'attribute_options', 'stock_levels'] as $table) {
            self::assertSame('0', $this->rows($table), $table);
        }
    }

    public function testABundleIsSavedWithItsItemsNamedAndHoldsNoBundle(): void
    {
        $this->actAs('administrator');
        $camera = $this->post('Camera');
        $battery = $this->post('Battery');
        $kit = $this->post('Camera kit');
        $this->request('PUT', "/products/{$camera}", ['price' => '300'], nonce: true);
        $this->request('PUT', "/products/{$battery}", ['price' => '20'], nonce: true);

        $saved = $this->request('PUT', "/products/{$kit}", ['price' => '0', 'bundle' => ['items' => [['product_id' => $camera, 'quantity' => 1], ['product_id' => $battery, 'quantity' => 2]], 'pricing' => 'sum', 'discount' => '10']], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        $bundle = $this->data($saved)['bundle'];
        self::assertIsArray($bundle);
        self::assertIsArray($bundle['items']);
        self::assertSame([['Camera', 1], ['Battery', 2]], array_map(static fn(mixed $item): array => \is_array($item) ? [$item['name'], $item['quantity']] : [], $bundle['items']));

        $nested = $this->request('PUT', "/products/{$camera}", ['price' => '300', 'bundle' => ['items' => [['product_id' => $kit, 'quantity' => 1]]]], nonce: true);
        self::assertSame(422, $nested->get_status(), 'a bundle holds no bundle');
        self::assertSame(422, $this->request('PUT', "/products/{$kit}", ['price' => '0', 'bundle' => ['items' => []]], nonce: true)->get_status(), 'a bundle holds something');
    }

    public function testTheCatalogScreensAreInTheShopMenu(): void
    {
        global $submenu;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $this->actAs('administrator');

        do_action('admin_menu');

        self::assertIsArray($submenu);
        $items = $submenu['orillacart'] ?? [];
        self::assertIsArray($items);
        self::assertContains('Attribute sets', array_column($items, 0));
        self::assertContains('Stockrooms', array_column($items, 0));
    }

    private function post(string $title): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => 'publish']);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        $this->posts[] = $id;

        return $id;
    }

    private function id(\WP_REST_Response $response): int
    {
        $id = $this->data($response)['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    private function rows(string $table): string
    {
        $count = $this->wpdb()->get_var("SELECT COUNT(*) FROM {$this->wpdb()->prefix}orillacart_{$table}");
        self::assertIsString($count);

        return $count;
    }

    private function wpdb(): \wpdb
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);

        return $wpdb;
    }

    private function clean(): void
    {
        foreach (['stock_levels', 'stockrooms', 'variations', 'products', 'attribute_sets'] as $table) {
            $this->wpdb()->query("DELETE FROM {$this->wpdb()->prefix}orillacart_{$table}");
        }
    }
}
