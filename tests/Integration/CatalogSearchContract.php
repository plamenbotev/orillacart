<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Search\Application\Dto\ClaimedJob;
use OrillaCart\Search\Application\Dto\Facet;
use OrillaCart\Search\Application\Dto\FacetCount;
use OrillaCart\Search\Application\Dto\OptionFilter;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchSort;
use OrillaCart\Search\Application\IndexWorker;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Domain\IndexJob;
use OrillaCart\Search\Infrastructure\Doctrine\DbalIndexQueue;
use OrillaCart\Search\Infrastructure\Doctrine\SearchTables;

/**
 * The storefront search, as every engine answers it: products reach the index through the queue as the shop
 * changes them, words find titles, texts and SKUs, and filters hold for one variant at a time.
 */
abstract class CatalogSearchContract extends RestTestCase
{
    private const array TABLES = ['search_filters', 'index_jobs', 'search_values', 'search_variants', 'search_documents', 'stock_levels', 'stockrooms', 'variations', 'products'];

    /** @var list<int> */
    private array $posts = [];
    /** @var list<array{int, non-empty-string}> */
    private array $terms = [];
    private int $lighting;
    private int $deskLamps;
    private int $acme;
    private int $lamp;
    private int $tv;
    private int $book;
    private int $shirt;
    private int $hoodie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->actAs('administrator');
        $this->lighting = $this->term('Lighting', ProductCategoryTaxonomy::NAME);
        $this->deskLamps = $this->term('Desk lamps', ProductCategoryTaxonomy::NAME, $this->lighting);
        $this->acme = $this->term('Acme', ProductBrandTaxonomy::NAME);

        $this->lamp = $this->product('Brass desk lamp', 'Warm light for reading.', ['price' => '40', 'sku' => 'LAMP-1', 'stock_mode' => 'always_available'], 1);
        wp_set_object_terms($this->lamp, [$this->deskLamps], ProductCategoryTaxonomy::NAME);
        wp_set_object_terms($this->lamp, [$this->acme], ProductBrandTaxonomy::NAME);
        $this->tv = $this->product('TV stand', 'Holds a big screen.', ['price' => '120', 'stock_mode' => 'unavailable'], 2);
        $this->book = $this->product('Lamps of the world', 'A book about the history of lamps.', ['price' => '15', 'stock_mode' => 'always_available'], 3);
        $this->product('Secret lamp', '', ['price' => '1', 'stock_mode' => 'always_available'], 4, 'draft');

        // The shirt comes as S in red (20, in stock) and M in blue (30, sold out); there is no M in red.
        $this->shirt = $this->product('Shirt', '', ['price' => '99', 'attributes' => [
            ['name' => 'Size', 'options' => [['name' => 'S'], ['name' => 'M']]],
            ['name' => 'Colour', 'options' => [['name' => 'Red'], ['name' => 'Blue']]],
        ]], 5);
        $options = $this->options($this->shirt);
        $this->variation($this->shirt, [$options['Size']['S'], $options['Colour']['Red']], '20', 'always_available');
        $this->variation($this->shirt, [$options['Size']['M'], $options['Colour']['Blue']], '30', 'unavailable');
        // A product without variations whose attribute has another name, and its option another case.
        $this->hoodie = $this->product('Hoodie', '', ['price' => '50', 'stock_mode' => 'always_available', 'attributes' => [
            ['name' => 'Размер', 'options' => [['name' => 'm']]],
        ]], 6);
        wp_set_current_user(0);
        $this->work();
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        foreach ($this->terms as [$term, $taxonomy]) {
            wp_delete_term($term, $taxonomy);
        }
        $_GET = [];
        $this->clean();
        parent::tearDown();
    }

    public function testWordsFindTitlesTextsAndSkusAndBestMatchesComeFirst(): void
    {
        self::assertSame([$this->lamp, $this->book], $this->find('lamp'), 'a word finds the words it starts');
        self::assertSame([$this->lamp], $this->find('reading'), 'and the text');
        self::assertSame([$this->tv], $this->find('tv'), 'words too short for FULLTEXT are looked for in the title');
        self::assertSame([$this->lamp, $this->book], $this->find('the lamp'), 'stopwords do not empty the result');
        self::assertSame([$this->book], $this->find('history lamps'), 'every word must match');
        self::assertSame([$this->lamp], $this->find('LAMP-1'), 'a SKU, whose short part no title holds');
        self::assertSame([$this->lamp], $this->find('lamp-1'), 'in any case');
        self::assertSame([], $this->find('secret'), 'drafts are not in the index');

        $this->actAs('administrator');
        $bulb = $this->product('Light bulb', '', ['price' => '3', 'stock_mode' => 'always_available'], 9);
        wp_set_current_user(0);
        $this->work();
        self::assertSame([$this->lamp, $bulb], $this->find('light', SearchSort::Order));
        self::assertSame([$bulb, $this->lamp], $this->find('light', SearchSort::Relevance), 'a title outweighs the text');
    }

    public function testOptionsPriceAndStockHoldForOneVariantAndFacetsCountWhatTheOtherFiltersLeave(): void
    {
        $size = static fn(string ...$values): OptionFilter => new OptionFilter('size', ['Size', 'Размер'], array_values($values));
        $colour = static fn(string ...$values): OptionFilter => new OptionFilter('colour', ['Colour'], array_values($values));

        self::assertSame([$this->shirt, $this->hoodie], $this->ids(new SearchQuery(options: [$size('M')])), 'by option name, under either attribute name');
        self::assertSame([$this->hoodie], $this->ids(new SearchQuery(options: [$size('M')], inStockOnly: true)), 'the shirt\'s M is sold out');
        self::assertSame([], $this->ids(new SearchQuery(options: [$size('M'), $colour('Red')])), 'the shirt has M and red, but not together');
        self::assertSame([$this->shirt], $this->ids(new SearchQuery(options: [$size('S'), $colour('Red', 'Blue')])), 'values of one filter are alternatives');
        self::assertSame([], $this->ids(new SearchQuery(options: [$size('S')], priceMin: '25')), 'the S costs 20');
        self::assertSame([$this->shirt], $this->ids(new SearchQuery(options: [$size('M')], priceMin: '25', priceMax: '30')));

        $result = $this->search(new SearchQuery(options: [$size('M'), $colour()], facets: true, limit: 50));
        self::assertSame(['m' => ['M', 2], 's' => ['S', 1]], self::counts($result->facet('size')), 'the size\'s own choice is ignored for its counts');
        self::assertSame(['blue' => ['Blue', 1]], self::counts($result->facet('colour')), 'with M chosen only blue is left');
        self::assertSame(['30', '50'], $result->priceRange, 'the prices of the variants that match');
    }

    public function testCategoriesHoldTheirSubcategoriesProductsAndBrandsFilterToo(): void
    {
        self::assertSame([$this->lamp], $this->ids(new SearchQuery(categoryIds: [$this->lighting])));
        self::assertSame([$this->lamp], $this->ids(new SearchQuery(brandIds: [$this->acme])));

        $result = $this->search(new SearchQuery(categoryIds: [$this->deskLamps], facets: true));
        self::assertSame([(string) $this->deskLamps => ['Desk lamps', 1], (string) $this->lighting => ['Lighting', 1]], self::counts($result->facet(Facet::CATEGORIES)));
        self::assertSame([(string) $this->acme => ['Acme', 1]], self::counts($result->facet(Facet::BRANDS)));
    }

    public function testTheShopPageNarrowsByTheShopsFiltersBrandPriceAndStockAndCountsWhatEachLeaves(): void
    {
        $this->actAs('administrator');
        $size = $this->request('POST', '/search-filters', ['label' => 'Size', 'key' => 'size', 'attributes' => ['Size', 'Размер']], nonce: true);
        self::assertSame(201, $size->get_status(), (string) wp_json_encode($size->get_data()));
        self::assertSame(422, $this->request('POST', '/search-filters', ['label' => 'Sizes', 'key' => 'size', 'attributes' => ['Size']], nonce: true)->get_status(), 'keys are unique');
        $names = array_values(array_filter($this->data($this->request('GET', '/search-filters/attributes')), \is_string(...)));
        self::assertSame(['Colour', 'Size', 'Размер'], array_values(array_intersect($names, ['Colour', 'Size', 'Размер'])));
        wp_set_current_user(0);

        $_GET = ['f' => ['size' => ['M']]];
        $page = do_blocks('<!-- wp:orillacart/products /-->');
        self::assertSame(['Shirt', 'Hoodie'], self::names($page));
        self::assertMatchesRegularExpression('#name="f\[size\]\[\]" value="m" checked>\s*M <span class="orillacart-facet__count">\(2\)</span>#', $page, 'the chosen value, counting both');
        self::assertMatchesRegularExpression('#value="s">\s*S <span class="orillacart-facet__count">\(1\)</span>#', $page, 'S counts though M is chosen');

        $_GET = ['f' => ['size' => ['m']], 'in_stock' => '1'];
        self::assertSame(['Hoodie'], self::names(do_blocks('<!-- wp:orillacart/products /-->')), 'the shirt\'s M is sold out');
        $_GET = ['price_min' => '45'];
        self::assertSame(['TV stand', 'Hoodie'], self::names(do_blocks('<!-- wp:orillacart/products /-->')));
        $_GET = ['brand' => [(string) $this->acme]];
        $branded = do_blocks('<!-- wp:orillacart/products /-->');
        self::assertSame(['Brass desk lamp'], self::names($branded));
        self::assertStringContainsString('Clear the filters', $branded);
        $_GET = [];
        self::assertStringNotContainsString('orillacart-facets', do_blocks('<!-- wp:orillacart/products {"filters":false} /-->'));

        $_GET = ['q' => 'lamp'];
        self::assertStringContainsString('value="lamp"', do_blocks('<!-- wp:orillacart/product-search /-->'), 'the search box keeps the words');
        $_GET = [];
    }

    public function testUnpublishedAndDeletedProductsLeaveTheIndex(): void
    {
        wp_update_post(['ID' => $this->book, 'post_status' => 'draft']);
        $this->work();
        self::assertSame([$this->lamp], $this->find('lamp'));

        wp_delete_post($this->lamp, true);
        $this->work();
        self::assertSame([], $this->find('lamp'));
    }

    public function testASaleThatStartsLaterIsBuiltAgainWhenItStarts(): void
    {
        $this->actAs('administrator');
        $tomorrow = new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC'));
        $saved = $this->request('PUT', "/products/{$this->lamp}", ['price' => '40', 'sku' => 'LAMP-1', 'stock_mode' => 'always_available', 'sale' => ['price' => '30', 'from' => $tomorrow->format(DATE_ATOM)]], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        wp_set_current_user(0);
        $this->work();

        self::assertSame([], $this->ids(new SearchQuery(onSaleOnly: true)), 'not yet');
        $status = $this->indexing()->status();
        self::assertSame([0, 1], [$status->due, $status->later], 'the lamp waits for its sale');
        self::assertEquals($tomorrow, $status->nextAt);
    }

    public function testStockChangesQueueTheirProducts(): void
    {
        $this->actAs('administrator');
        $stockroom = $this->data($this->request('POST', '/stockrooms', ['name' => 'Sofia'], nonce: true))['id'];
        $this->request('PUT', "/products/{$this->tv}", ['price' => '120', 'stock_mode' => 'tracked'], nonce: true);
        $this->request('PUT', "/stock/product/{$this->tv}", ['levels' => [['stockroom_id' => $stockroom, 'quantity' => 0]]], nonce: true);
        $this->work();
        self::assertNotContains($this->tv, $this->ids(new SearchQuery(inStockOnly: true)));

        $this->request('PUT', "/stock/product/{$this->tv}", ['levels' => [['stockroom_id' => $stockroom, 'quantity' => 3]]], nonce: true);
        wp_set_current_user(0);
        self::assertSame(1, $this->indexing()->status()->due);
        $this->work();
        self::assertContains($this->tv, $this->ids(new SearchQuery(inStockOnly: true)));
    }

    public function testWorkersTakeDifferentProductsAndAChangeWhileBuildingIsBuiltAgain(): void
    {
        $queue = $this->queue();
        $now = new \DateTimeImmutable('2030-01-01 12:00:00');
        $later = $now->modify('+5 minutes');
        $queue->push([900001, 900002], $now);

        $first = $queue->claim(1, $now, $later);
        $second = $queue->claim(10, $now, $later);
        self::assertSame([900001], array_map(static fn(ClaimedJob $job): int => $job->productId, $first));
        self::assertSame([900002], array_map(static fn(ClaimedJob $job): int => $job->productId, $second), 'the first one is taken');
        self::assertSame([], $queue->claim(10, $now, $later));

        $queue->push([900001], $now);
        $queue->complete($first[0], null);
        $again = $queue->claim(10, $now, $later);
        self::assertSame([[900001, 2]], array_map(static fn(ClaimedJob $job): array => [$job->productId, $job->version], $again), 'it changed while it was built');
        $queue->complete($again[0], null);
        $queue->complete($second[0], $now->modify('+1 day'));
        self::assertSame([0, 1], [$queue->status($now)->due, $queue->status($now)->later], 'one waits for its sale');

        $queue->push([900002], $now);
        self::assertSame(1, $queue->status($now)->due, 'a change makes it due now');
        for ($attempt = 1; $attempt <= IndexJob::MAX_ATTEMPTS; ++$attempt) {
            $job = $queue->claim(10, $now, $later);
            self::assertCount(1, $job);
            $queue->fail($job[0], 'Broken', $now);
        }
        self::assertSame([], $queue->claim(10, $now, $later), 'it failed too often');
        self::assertSame([900002 => 'Broken'], $queue->status($now)->parked);
        $queue->push([900002], $now);
        self::assertCount(1, $queue->claim(10, $now, $later), 'until it changes again');
    }

    /**
     * @return list<string> the products on the page, in order
     */
    private static function names(string $html): array
    {
        preg_match_all('#class="orillacart-product-card__name">([^<]+)<#', $html, $found);

        return $found[1];
    }

    /**
     * @return list<int>
     */
    private function find(string $text, SearchSort $sort = SearchSort::Order): array
    {
        return $this->ids(new SearchQuery(text: $text, sort: $sort));
    }

    /**
     * @return list<int>
     */
    private function ids(SearchQuery $query): array
    {
        return $this->search($query)->ids;
    }

    private function search(SearchQuery $query): \OrillaCart\Search\Application\Dto\SearchResult
    {
        $search = self::kernel()->container()->get(ShopSearch::class);
        self::assertInstanceOf(ShopSearch::class, $search);

        return $search->search($query);
    }

    /**
     * @return array<string, array{string, int}>
     */
    private static function counts(?Facet $facet): array
    {
        self::assertNotNull($facet);
        $counts = [];
        foreach ($facet->values as $value) {
            self::assertInstanceOf(FacetCount::class, $value);
            $counts[$value->value] = [$value->label, $value->count];
        }

        return $counts;
    }

    protected function work(): void
    {
        $worker = self::kernel()->container()->get(IndexWorker::class);
        self::assertInstanceOf(IndexWorker::class, $worker);
        while ($worker->work(100)->count() > 0);
        // A run with nothing left marks a new engine's index built.
        $worker->work(100);
    }

    private function indexing(): SearchIndexing
    {
        $indexing = self::kernel()->container()->get(SearchIndexing::class);
        self::assertInstanceOf(SearchIndexing::class, $indexing);

        return $indexing;
    }

    private function queue(): DbalIndexQueue
    {
        $entityManager = new EntityManagerFactory(DatabaseConfig::fromWordPress(), new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->create();

        return new DbalIndexQueue(new SearchTables($entityManager));
    }

    /**
     * @param array<string, mixed> $details
     */
    private function product(string $title, string $content, array $details, int $order, string $status = 'publish'): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_content' => $content, 'post_status' => $status, 'menu_order' => $order]);
        self::assertIsInt($id);
        $this->posts[] = $id;
        $saved = $this->request('PUT', "/products/{$id}", $details, nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        return $id;
    }

    /**
     * @return array<string, array<string, int>> option ids by attribute and option name
     */
    private function options(int $product): array
    {
        $offered = $this->data($this->request('GET', "/products/{$product}"))['offered'];
        self::assertIsArray($offered);
        $ids = [];
        foreach ($offered as $attribute) {
            self::assertIsArray($attribute);
            self::assertIsString($attribute['name']);
            self::assertIsArray($attribute['options']);
            foreach ($attribute['options'] as $option) {
                self::assertIsArray($option);
                self::assertIsString($option['name']);
                self::assertIsInt($option['id']);
                $ids[$attribute['name']][$option['name']] = $option['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $optionIds
     */
    private function variation(int $product, array $optionIds, string $price, string $stockMode): void
    {
        $saved = $this->request('POST', "/products/{$product}/variations", ['option_ids' => $optionIds, 'price' => $price, 'stock_mode' => $stockMode], nonce: true);
        self::assertSame(201, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
    }

    /**
     * @param non-empty-string $taxonomy
     */
    private function term(string $name, string $taxonomy, int $parent = 0): int
    {
        $term = wp_insert_term($name, $taxonomy, ['parent' => max(0, $parent)]);
        self::assertIsArray($term);
        $this->terms[] = [$term['term_id'], $taxonomy];

        return $term['term_id'];
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (self::TABLES as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
