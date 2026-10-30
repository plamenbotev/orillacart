<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Application\Dto\ProductFilter;
use OrillaCart\Catalog\Application\ProductFinder;
use OrillaCart\Catalog\Domain\ProductCriteria;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Tests\Fixtures\Extension\TestAddOn;

/**
 * Finding products on the admin list: the database's answers to each filter, and the list itself searching SKUs
 * and narrowing by what WordPress keeps (a picture, a category).
 */
final class ProductListFiltersTest extends RestTestCase
{
    /** @var array<string, int> by name */
    private array $ids = [];
    private int $sofia;
    private int $plovdiv;
    private int $term = 0;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['stock_movements', 'stock_levels', 'stockrooms', 'variations', 'products', 'attribute_sets'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
        foreach (get_posts(['post_type' => ProductPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $left) {
            wp_delete_post((int) $left, true);
        }
        $this->actAs('administrator');
        $this->sofia = $this->id($this->request('POST', '/stockrooms', ['name' => 'Sofia'], nonce: true));
        $this->plovdiv = $this->id($this->request('POST', '/stockrooms', ['name' => 'Plovdiv'], nonce: true));

        $this->product('Camera', ['price' => '300', 'sku' => 'CAM-1', 'weight' => '1.2', 'featured' => true], [$this->sofia => 5]);
        $this->product('Battery', ['price' => '20', 'sku' => 'BAT', 'weight' => '0.1', 'sale' => ['price' => '15']], [$this->sofia => 1]);
        $shirt = $this->product('Shirt', ['price' => '25', 'sku' => 'SHIRT', 'attributes' => [['name' => 'Size', 'options' => [['name' => 'S'], ['name' => 'L']]]]], []);
        $offered = $this->data($this->request('GET', "/products/{$shirt}"))['offered'] ?? null;
        self::assertIsArray($offered);
        self::assertIsArray($offered[0]);
        self::assertIsArray($offered[0]['options']);
        foreach (array_values($offered[0]['options']) as $index => $option) {
            self::assertIsArray($option);
            $variation = $this->id($this->request('POST', "/products/{$shirt}/variations", ['option_ids' => [$option['id']], 'price' => $index === 0 ? '25' : '45', 'sku' => $index === 0 ? 'SHIRT-S' : null], nonce: true));
            if ($index === 0) {
                $this->setStock('variation', $variation, [$this->plovdiv => 4]);
            }
        }
        $this->product('Poster', ['price' => '0', 'price_visibility' => 'hidden', 'purchasable' => false], [$this->sofia => 0]);
        $this->product('Ebook', ['price' => '9', 'type' => 'virtual', 'sku' => 'BOOK', 'stock_mode' => 'always_available'], []);
        $this->product('Gift', ['price' => '30', 'type' => 'virtual', 'sku' => 'GIFT', 'stock_mode' => 'unavailable'], []);
        $this->product('Speaker', ['price' => '60', 'sku' => 'SPK', 'weight' => '2'], [$this->sofia => 0]);
        $this->stock()->take([StockItemRef::product($this->ids['Speaker'])], 2, backorders: true);
        $this->product('Kit', ['price' => '0', 'type' => 'virtual', 'sku' => 'KIT', 'bundle' => ['items' => [
            ['product_id' => $this->ids['Camera'], 'quantity' => 1],
            ['product_id' => $this->ids['Battery'], 'quantity' => 2],
        ], 'pricing' => 'sum']], []);
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $post) {
            wp_delete_post($post, true);
        }
        if ($this->term > 0) {
            wp_delete_term($this->term, ProductCategoryTaxonomy::NAME);
        }
        unset($GLOBALS['current_screen']);
        $_GET = [];
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{ProductFilter, list<string>}>
     */
    public static function filters(): iterable
    {
        yield 'ships' => [new ProductFilter(kind: ProductCriteria::SHIPS), ['Camera', 'Battery', 'Shirt', 'Poster', 'Speaker']];
        yield 'with variations' => [new ProductFilter(kind: ProductCriteria::WITH_VARIATIONS), ['Shirt']];
        yield 'bundles' => [new ProductFilter(kind: ProductCriteria::BUNDLE), ['Kit']];
        yield 'featured' => [new ProductFilter(shown: ProductCriteria::FEATURED), ['Camera']];
        yield 'on sale' => [new ProductFilter(shown: ProductCriteria::ON_SALE), ['Battery']];
        yield 'price hidden' => [new ProductFilter(shown: ProductCriteria::PRICE_HIDDEN), ['Poster']];
        yield 'not purchasable' => [new ProductFilter(shown: ProductCriteria::NOT_PURCHASABLE), ['Poster']];
        yield 'the sale price is charged' => [new ProductFilter(minPrice: '10', maxPrice: '15'), ['Battery']];
        yield 'a variation in range' => [new ProductFilter(minPrice: '40', maxPrice: '50'), ['Shirt']];
        yield 'missing a SKU' => [new ProductFilter(missing: ProductCriteria::NO_SKU), ['Shirt', 'Poster']];
        yield 'missing a price' => [new ProductFilter(missing: ProductCriteria::NO_PRICE), ['Poster']];
        yield 'missing a weight' => [new ProductFilter(missing: ProductCriteria::NO_WEIGHT), ['Shirt', 'Poster']];
        yield 'in stock' => [new ProductFilter(stock: ProductFinder::IN_STOCK), ['Camera', 'Battery', 'Shirt', 'Ebook']];
        yield 'some variations out' => [new ProductFilter(stock: ProductFinder::SOME_OUT), ['Shirt']];
        yield 'out of stock, the kit short of batteries' => [new ProductFilter(stock: ProductFinder::OUT_OF_STOCK), ['Poster', 'Speaker', 'Kit']];
        yield 'owed' => [new ProductFilter(stock: ProductFinder::OWED), ['Speaker']];
        yield 'not for sale' => [new ProductFilter(stock: ProductFinder::NOT_FOR_SALE), ['Gift']];
        yield 'filters add up' => [new ProductFilter(kind: ProductCriteria::SHIPS, stock: ProductFinder::IN_STOCK, missing: ProductCriteria::NO_WEIGHT), ['Shirt']];
    }

    /**
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('filters')]
    public function testTheDatabaseFindsWhatEachFilterNarrowsTo(ProductFilter $filter, array $expected): void
    {
        self::assertSame($this->names($expected), $this->finder()->ids($filter));
    }

    public function testStockCountedInOneStockroom(): void
    {
        $finder = $this->finder();
        self::assertSame($this->names(['Shirt']), $finder->ids(new ProductFilter(stockroom: $this->plovdiv)));
        self::assertSame($this->names(['Camera', 'Battery']), $finder->ids(new ProductFilter(stock: ProductFinder::IN_STOCK, stockroom: $this->sofia)));
    }

    public function testTheListSearchesSkusAndNumbersAndNarrowsByWhatWordPressKeeps(): void
    {
        self::assertSame($this->names(['Shirt']), $this->listed(['s' => 'shirt-s']), 'a variation\'s SKU, in any case');
        self::assertSame($this->names(['Camera']), $this->listed(['s' => (string) $this->ids['Camera']]));
        self::assertSame($this->names(['Speaker']), $this->listed(['s' => 'spk', 'orillacart_stock' => 'owed']), 'search and filters together');

        // Deleted with the products; not one of them.
        $this->ids['picture'] = $this->attachment();
        set_post_thumbnail($this->ids['Camera'], $this->ids['picture']);
        $term = wp_insert_term('Audio', ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->term = $term['term_id'];
        wp_set_object_terms($this->ids['Speaker'], [$this->term], ProductCategoryTaxonomy::NAME);

        self::assertNotContains($this->ids['Camera'], $this->listed(['orillacart_missing' => 'picture']));
        self::assertContains($this->ids['Battery'], $this->listed(['orillacart_missing' => 'picture']));
        self::assertNotContains($this->ids['Speaker'], $this->listed(['orillacart_missing' => 'category']));
        self::assertSame($this->names(['Poster', 'Kit']), $this->listed(['orillacart_stock' => 'out_of_stock', 'orillacart_missing' => 'category']));
        self::assertSame([], $this->listed(['orillacart_stock' => 'owed', 'orillacart_kind' => 'bundle']), 'none found lists none');
        self::assertCount(\count($this->ids) - 1, $this->listed(['orillacart_stock' => 'nonsense', 'orillacart_min_price' => '1e3']), 'what is not a filter narrows nothing');
    }

    public function testAnAddOnsFilterAndSearchNarrowTheList(): void
    {
        $registry = TestAddOn::$registry;
        self::assertNotNull($registry);
        self::assertTrue($registry->products()->mark($this->ids['Camera'], 'test_marketplace'));
        self::assertTrue($registry->products()->mark($this->ids['Shirt'], 'test_marketplace'));
        self::assertFalse($registry->products()->mark(999999, 'test_marketplace'));
        TestAddOn::$barcodes = ['EAN3800123' => [$this->ids['Speaker']]];
        try {
            self::assertSame($this->names(['Camera', 'Shirt']), $this->listed(['orillacart_addon' => 'test_marketplace']));
            self::assertSame($this->names(['Shirt']), $this->listed(['orillacart_addon' => 'test_marketplace', 'orillacart_kind' => 'variations']));
            self::assertSame($this->names(['Speaker']), $this->listed(['s' => 'EAN3800123']), 'the add-on\'s barcode');
        } finally {
            TestAddOn::$barcodes = [];
        }
        wp_delete_post($this->ids['Camera'], true);
        self::assertFalse($registry->products()->isMarked($this->ids['Camera'], 'test_marketplace'), 'gone with the product');
    }

    /**
     * The product list's own query, as WordPress runs it on its screen.
     *
     * @param array<string, string> $get
     *
     * @return list<int> in ascending order
     */
    private function listed(array $get): array
    {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        set_current_screen('edit-' . ProductPostType::NAME);
        $_GET = $get;
        $query = new \WP_Query();
        $GLOBALS['wp_the_query'] = $query;
        $posts = $query->query(['post_type' => ProductPostType::NAME, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 's' => $get['s'] ?? '']);
        $_GET = [];

        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $posts));
    }

    /**
     * @param list<string> $names
     *
     * @return list<int>
     */
    private function names(array $names): array
    {
        $ids = array_map(fn(string $name): int => $this->ids[$name], $names);
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, int>      $stock   by stockroom
     */
    private function product(string $title, array $details, array $stock): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->ids[$title] = $id;
        $saved = $this->request('PUT', "/products/{$id}", $details, nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        if ($stock !== []) {
            self::assertSame(200, $this->setStock('product', $id, $stock)->get_status());
        }

        return $id;
    }

    private function finder(): ProductFinder
    {
        $finder = self::kernel()->container()->get(ProductFinder::class);
        self::assertInstanceOf(ProductFinder::class, $finder);

        return $finder;
    }

    private function stock(): StockService
    {
        $stock = self::kernel()->container()->get(StockService::class);
        self::assertInstanceOf(StockService::class, $stock);

        return $stock;
    }

    private function attachment(): int
    {
        $id = wp_insert_attachment(['post_title' => 'Camera', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], 'camera.png');
        self::assertIsInt($id);

        return $id;
    }

    private function id(\WP_REST_Response $response): int
    {
        $id = $this->data($response)['id'] ?? null;
        self::assertIsInt($id, (string) wp_json_encode($response->get_data()));

        return $id;
    }
}
