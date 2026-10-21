<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Search\Application\Dto\Facet;
use OrillaCart\Search\Application\Dto\FacetCount;
use OrillaCart\Search\Application\Dto\OptionFilter;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchSort;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Search\Application\ShopSearch;

/**
 * The storefront search, as every engine answers it: products reach the index through the queue as the shop
 * changes them, words find titles, texts and SKUs, and filters hold for one variant at a time.
 */
abstract class CatalogSearchContract extends RestTestCase
{
    private const array TABLES = ['price_rules', 'search_filters', 'jobs', 'failed_jobs', 'search_values', 'search_variants', 'search_documents', 'stock_levels', 'stockrooms', 'variations', 'products'];

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

    public function testOnlyTheProductsAChangeTouchesAreQueued(): void
    {
        $this->actAs('administrator');
        $kit = $this->product('Lamp kit', '', ['price' => '0', 'bundle' => ['items' => [['product_id' => $this->lamp, 'quantity' => 2]], 'pricing' => 'sum']], 7);
        $this->work();

        // A description is not in the index; a name is, for every product below the category.
        wp_update_term($this->lighting, ProductCategoryTaxonomy::NAME, ['description' => 'All the lights.']);
        self::assertSame([], $this->queued());
        wp_update_term($this->lighting, ProductCategoryTaxonomy::NAME, ['name' => 'Lights']);
        self::assertSame([$this->lamp, $kit], $this->queued(), 'the lamp is in a category below it, and the kit holds the lamp');
        $this->work();
        $home = max(1, $this->term('Home', ProductCategoryTaxonomy::NAME));
        wp_update_term($this->deskLamps, ProductCategoryTaxonomy::NAME, ['parent' => $home]);
        self::assertSame([$this->lamp, $kit], $this->queued(), 'moved under another category');
        $this->work();
        wp_update_term($this->acme, ProductBrandTaxonomy::NAME, ['name' => 'Acme Lighting']);
        self::assertSame([$this->lamp, $kit], $this->queued(), 'a brand renamed');
        $this->work();

        $group = $this->request('POST', '/customer-groups', ['name' => 'Trade'], nonce: true);
        self::assertSame(201, $group->get_status(), (string) wp_json_encode($group->get_data()));
        $groupId = $this->data($group)['id'];
        self::assertIsInt($groupId);
        try {
            $rule = function (string $for = '', array $ids = []): int {
                $body = ['name' => 'Rule', 'tiers' => [['min_quantity' => 1, 'kind' => 'percent', 'value' => '10']]];
                if ($for !== '') {
                    $body[$for] = $ids;
                }
                $id = $this->data($this->request('POST', '/price-rules', $body, nonce: true))['id'];
                self::assertIsInt($id);

                return $id;
            };
            $rule('group_ids', [$groupId]);
            self::assertSame([], $this->queued(), 'guests, whose prices the index keeps, do not get it');
            $brand = $rule('brand_ids', [$this->acme]);
            self::assertSame([$this->lamp, $kit], $this->queued());
            $this->work();
            $this->request('PUT', "/price-rules/{$brand}", ['name' => 'Rule', 'tiers' => [['min_quantity' => 1, 'kind' => 'percent', 'value' => '10']], 'product_ids' => [$this->book]], nonce: true);
            self::assertSame([$this->lamp, $this->book, $kit], $this->queued(), 'the products it was for, and is for now');
            $this->work();
            $rule();
            self::assertCount(7, $this->queued(), 'a rule for the whole catalog');
        } finally {
            $this->request('DELETE', "/customer-groups/{$groupId}", nonce: true);
            wp_set_current_user(0);
        }
        $this->work();
        wp_delete_term($this->deskLamps, ProductCategoryTaxonomy::NAME);
        self::assertSame([$this->lamp, $kit], $this->queued(), 'its category deleted');
    }

    public function testRebuildingRemovesWhatTheIndexMissedWhenAProductLeft(): void
    {
        // The TV stand's commerce details go without the shop hearing of it, as a crash in between would.
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->delete("{$wpdb->prefix}orillacart_products", ['id' => $this->tv]);
        self::assertSame([$this->tv], $this->find('tv'), 'the index still has it');

        $this->indexing()->queueEverything();
        $this->work();

        self::assertSame([], $this->find('tv'));
        self::assertSame([$this->lamp, $this->book], $this->find('lamp'), 'the rest stay');
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

    public function testAFiltersBlockBesideTheProductsCountsWithinWhatTheyShowAndBothKeepEachOthersChoices(): void
    {
        $this->actAs('administrator');
        $this->request('POST', '/search-filters', ['label' => 'Size', 'key' => 'size', 'attributes' => ['Size', 'Размер']], nonce: true);
        $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Clothes', 'post_content' => '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:orillacart/product-filters /--></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:orillacart/products {"filters":false,"onSale":false,"category":"lighting"} /--></div><!-- /wp:column --></div><!-- /wp:columns -->']);
        self::assertIsInt($page);
        $this->posts[] = $page;
        wp_set_current_user(0);
        $GLOBALS['post'] = get_post($page);

        $_GET = ['brand' => [(string) $this->acme]];
        $html = do_blocks((string) get_post_field('post_content', $page));
        self::assertSame(['Brass desk lamp'], self::names($html));
        self::assertStringContainsString('data-orillacart-region="filters"', $html);
        self::assertMatchesRegularExpression('#name="brand\\[\\]" value="' . $this->acme . '" checked>\\s*Acme <span class="orillacart-facet__count">\\(1\\)</span>#', $html);
        self::assertStringNotContainsString('name="f[size][]"', $html, 'no size within Lighting: the shirts and the hoodie are not there');
        self::assertStringContainsString('<input type="hidden" name="brand[]" value="' . $this->acme . '">', $html, 'the products\' search keeps the brand');
        self::assertStringNotContainsString('<input type="hidden" name="category"', $html, 'the block\'s own category is not the address\'s');
        unset($GLOBALS['post']);
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
        self::assertSame(1, $this->indexing()->status()->due);
        $this->work();
        self::assertContains($this->tv, $this->ids(new SearchQuery(inStockOnly: true)));

        $this->request('PUT', "/stock/product/{$this->tv}", ['levels' => [['stockroom_id' => $stockroom, 'quantity' => 5]]], nonce: true);
        wp_set_current_user(0);
        self::assertSame(0, $this->indexing()->status()->due, 'still in stock: nothing the index shows changed');
    }

    // Last: the shop reads its filters once a request, so a test that makes filters renders it before this does.
    public function testTagsAreWordsAndAFilterAndARenamedTagQueuesItsProducts(): void
    {
        $this->actAs('administrator');
        $gift = $this->term('Gift idea', ProductTagTaxonomy::NAME);
        wp_set_object_terms($this->book, [$gift], ProductTagTaxonomy::NAME);
        self::assertSame([$this->book], $this->queued(), 'tagging a product');
        $this->work();

        self::assertSame([$this->book], $this->find('gift'), 'a tag\'s name finds its products');
        self::assertSame([$this->book], $this->ids(new SearchQuery(tagIds: [$gift])));
        self::assertSame([], $this->ids(new SearchQuery(categoryIds: [$this->lighting], tagIds: [$gift])), 'with the other filters');

        wp_update_term($gift, ProductTagTaxonomy::NAME, ['name' => 'Present']);
        self::assertSame([$this->book], $this->queued(), 'a tag renamed');
        $this->work();
        self::assertSame([$this->book], $this->find('present'));
        self::assertSame([], $this->find('gift'));

        $_GET = ['tag' => 'gift-idea'];
        self::assertSame(['Lamps of the world'], self::names(do_blocks('<!-- wp:orillacart/products /-->')), 'the shop page narrows by ?tag=');
        $_GET = [];
        self::assertSame(['Lamps of the world'], self::names(do_blocks('<!-- wp:orillacart/products {"tag":"gift-idea"} /-->')), 'or a block by its own');
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
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['search'], 100, 'test')->count() > 0);
    }

    /**
     * The products waiting to be indexed, by id.
     *
     * @return list<int>
     */
    private function queued(): array
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $table = $wpdb->prefix . 'orillacart_jobs';
        $keys = $wpdb->get_col($wpdb->prepare('SELECT unique_key FROM %i WHERE type = %s AND attempts = 0', $table, 'search.index'));
        $ids = array_map('intval', $keys);
        sort($ids);

        return $ids;
    }

    private function indexing(): SearchIndexing
    {
        $indexing = self::kernel()->container()->get(SearchIndexing::class);
        self::assertInstanceOf(SearchIndexing::class, $indexing);

        return $indexing;
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
