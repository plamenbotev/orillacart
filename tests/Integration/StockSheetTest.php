<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Application\Dto\LowStockView;
use OrillaCart\Catalog\Application\LowStock;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

/**
 * The stock sheet over the REST API: finding products by name, SKU, category and parent, their variations in
 * their place, attribute options, and saving many cells at once.
 */
final class StockSheetTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    private int $term = 0;
    private int $sofia;
    private int $berlin;
    private int $lamp;
    private int $hoodie;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['stock_levels', 'stockrooms', 'variations', 'products', 'attribute_sets'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
        $this->actAs('administrator');
        $this->sofia = $this->id($this->request('POST', '/stockrooms', ['name' => 'Sofia'], nonce: true));
        $this->berlin = $this->id($this->request('POST', '/stockrooms', ['name' => 'Berlin'], nonce: true));

        $term = wp_insert_term('Lighting', ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->term = $term['term_id'];
        $child = wp_insert_term('Desk lamps', ProductCategoryTaxonomy::NAME, ['parent' => max(0, $this->term)]);
        self::assertIsArray($child);

        $this->lamp = $this->product('Brass lamp', ['price' => '30', 'sku' => 'LAMP-1']);
        wp_set_object_terms($this->lamp, [$child['term_id']], ProductCategoryTaxonomy::NAME);
        $this->hoodie = $this->product('Hoodie', ['price' => '40', 'attributes' => [['name' => 'Size', 'options' => [['name' => 'S'], ['name' => 'M']]]]]);
        $offered = $this->data($this->request('GET', "/products/{$this->hoodie}"))['offered'] ?? null;
        self::assertIsArray($offered);
        self::assertIsArray($offered[0]);
        $options = $offered[0]['options'];
        self::assertIsArray($options);
        foreach ($options as $index => $option) {
            self::assertIsArray($option);
            $this->request('POST', "/products/{$this->hoodie}/variations", ['option_ids' => [$option['id']], 'price' => '40', 'sku' => 'HOOD-' . $index], nonce: true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        $terms = get_terms(['taxonomy' => ProductCategoryTaxonomy::NAME, 'hide_empty' => false, 'fields' => 'ids']);
        foreach (\is_array($terms) ? $terms : [] as $term) {
            wp_delete_term((int) $term, ProductCategoryTaxonomy::NAME);
        }
        parent::tearDown();
    }

    public function testVariationsTakeTheirProductsPlaceAndFiltersNarrowTheSheet(): void
    {
        self::assertSame(401, (function () {
            wp_set_current_user(0);

            return $this->request('GET', '/stock-sheet')->get_status();
        })());
        $this->actAs('administrator');

        $sheet = $this->sheet('');
        self::assertSame(['Brass lamp', 'Hoodie – S', 'Hoodie – M'], array_column($sheet['rows'], 'name'));
        self::assertSame(['Berlin', 'Sofia'], array_column($sheet['stockrooms'], 'name'), 'by name');

        self::assertSame(['Hoodie – S', 'Hoodie – M'], array_column($this->sheet('search=HOOD-1')['rows'], 'name'), 'a variation\'s SKU finds its product');
        self::assertSame(['Brass lamp'], array_column($this->sheet('search=brass')['rows'], 'name'));
        self::assertSame(['Brass lamp'], array_column($this->sheet("search={$this->lamp}")['rows'], 'name'), 'by id');
        $variation = $sheet['rows'][2]['id'];
        self::assertSame(['Hoodie – S', 'Hoodie – M'], array_column($this->sheet("search={$variation}")['rows'], 'name'), 'a variation\'s id finds its product');
        self::assertSame(['Brass lamp'], array_column($this->sheet("category={$this->term}")['rows'], 'name'), 'subcategories count');
        self::assertSame(['Hoodie – S', 'Hoodie – M'], array_column($this->sheet("parent={$this->hoodie}")['rows'], 'name'));
        self::assertSame(['Size: S', 'Size: M'], array_column($this->sheet('kind=options')['rows'], 'name'));
        self::assertSame(['Berlin'], array_column($this->sheet("stockroom={$this->berlin}")['stockrooms'], 'name'));
        self::assertSame(2, $this->sheet('per_page=1')['pages'], 'pages count products');
        self::assertSame(['Hoodie – S', 'Hoodie – M'], array_column($this->sheet('per_page=1&page=2')['rows'], 'name'), 'a product\'s variations stay on its page');
    }

    public function testManyCellsAreSavedTogether(): void
    {
        $variation = $this->sheet("parent={$this->hoodie}")['rows'][0]['id'];

        $saved = $this->request('PATCH', '/stock-levels', ['changes' => [
            ['kind' => 'product', 'id' => $this->lamp, 'stockroom_id' => $this->sofia, 'quantity' => 5],
            ['kind' => 'variation', 'id' => $variation, 'stockroom_id' => $this->berlin, 'quantity' => 0],
        ]], nonce: true);
        self::assertSame(204, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        $rows = $this->sheet('')['rows'];
        self::assertSame([[(string) $this->sofia => 5], [(string) $this->berlin => 0]], [$rows[0]['levels'], $rows[1]['levels']]);

        $refused = $this->request('PATCH', '/stock-levels', ['changes' => [
            ['kind' => 'product', 'id' => $this->lamp, 'stockroom_id' => $this->sofia, 'quantity' => null],
            ['kind' => 'product', 'id' => $this->lamp, 'stockroom_id' => $this->sofia, 'quantity' => -1],
        ]], nonce: true);
        self::assertSame(422, $refused->get_status());
        self::assertSame([(string) $this->sofia => 5], $this->sheet('')['rows'][0]['levels'], 'nothing changed');
    }

    public function testTheLowStockIsFoundOnTheDatabaseWithEachThreshold(): void
    {
        $sheet = $this->sheet("parent={$this->hoodie}")['rows'];
        $small = $sheet[0]['id'];
        $this->request('PUT', "/products/{$this->hoodie}/variations/{$small}", ['option_ids' => [$this->sheet('kind=options')['rows'][0]['id']], 'price' => '40', 'sku' => 'HOOD-0', 'low_stock_threshold' => 6], nonce: true);
        $this->request('PUT', "/stockrooms/{$this->berlin}", ['name' => 'Berlin', 'low_stock_threshold' => 9], nonce: true);
        $option = $this->sheet('kind=options')['rows'][1]['id'];
        $saved = $this->request('PATCH', '/stock-levels', ['changes' => [
            ['kind' => 'product', 'id' => $this->lamp, 'stockroom_id' => $this->sofia, 'quantity' => 3],
            ['kind' => 'product', 'id' => $this->lamp, 'stockroom_id' => $this->berlin, 'quantity' => 9],
            ['kind' => 'variation', 'id' => $small, 'stockroom_id' => $this->sofia, 'quantity' => 5],
            ['kind' => 'option', 'id' => $option, 'stockroom_id' => $this->sofia, 'quantity' => 1],
        ]], nonce: true);
        self::assertSame(204, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        $low = self::kernel()->container()->get(LowStock::class);
        self::assertInstanceOf(LowStock::class, $low);
        self::assertSame([
            ['Brass lamp', 'Berlin', 9, 9],
            ['Hoodie – S', 'Sofia', 5, 6],
            ['Size: M', 'Sofia', 1, 2],
        ], array_map(static fn(LowStockView $item): array => [$item->name, $item->stockroom, $item->quantity, $item->threshold], $low->items()), 'the lamp holds 3 in Sofia, above the shop\'s 2');
    }

    /**
     * @return array{stockrooms: list<array{name: string}>, rows: list<array{name: string, id: int, levels: array<string, int>}>, pages: int}
     */
    private function sheet(string $query): array
    {
        $response = $this->request('GET', '/stock-sheet' . ($query === '' ? '' : '?' . $query));
        self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        /** @var array{stockrooms: list<array{name: string}>, rows: list<array{name: string, id: int, levels: array<string, int>}>, pages: int} */
        return json_decode((string) wp_json_encode($response->get_data()), true);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function product(string $title, array $details): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", $details, nonce: true)->get_status());

        return $id;
    }

    private function id(\WP_REST_Response $response): int
    {
        $id = $this->data($response)['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }
}
