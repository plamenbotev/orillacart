<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Reviews\Infrastructure\WordPress\CommentReviewStore;

/**
 * Product pages and lists made of the product blocks (design 50): rendered on the server, so the page's first
 * response holds the product's name, price, buying form and reviews; the description comes alone once the
 * template has the blocks, and the card the shop designs shows each product of the list.
 */
final class ProductBlocksTest extends RestTestCase
{
    private const array TABLES = ['jobs', 'failed_jobs', 'search_values', 'search_variants', 'search_documents', 'stock_levels', 'products'];

    private int $lamp;
    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->actAs('administrator');
        $term = wp_insert_term('Lighting ' . uniqid(), ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->category = $term['term_id'];
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Brass lamp', 'post_content' => '<p>Warm light for reading.</p>', 'post_status' => 'publish', 'comment_status' => 'open']);
        self::assertIsInt($id);
        $this->lamp = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '40', 'sale' => ['price' => '35'], 'sku' => 'LAMP-' . $id, 'stock_mode' => 'always_available'], nonce: true)->get_status());
        wp_set_object_terms($id, [$this->category], ProductCategoryTaxonomy::NAME);
        $review = wp_insert_comment(['comment_post_ID' => $id, 'comment_type' => CommentReviewStore::TYPE, 'comment_author' => 'Bob', 'comment_content' => 'Lovely.', 'comment_approved' => 1]);
        self::assertIsInt($review);
        add_comment_meta($review, CommentReviewStore::RATING, 4);
        wp_set_current_user(0);
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['search'], 100, 'test')->count() > 0);
    }

    protected function tearDown(): void
    {
        wp_delete_post($this->lamp, true);
        wp_delete_term($this->category, ProductCategoryTaxonomy::NAME);
        $this->clean();
        parent::tearDown();
    }

    public function testThePluginsTemplateShowsTheProductAsBlocksInTheFirstResponse(): void
    {
        $template = get_block_template('orillacart//single-' . ProductPostType::NAME);
        if (!$template instanceof \WP_Block_Template) {
            self::markTestSkipped('The test site\'s theme is not a block theme.');
        }
        $html = $this->onProductPage(static function () use ($template): string {
            global $_wp_current_template_content;
            $_wp_current_template_content = $template->content;
            try {
                return do_blocks($template->content);
            } finally {
                $_wp_current_template_content = null;
            }
        });

        self::assertStringContainsString('Brass lamp', $html);
        self::assertStringContainsString('Warm light for reading.', $html, 'the description');
        self::assertStringContainsString('data-orillacart-price', $html);
        self::assertMatchesRegularExpression('#<del[^>]*>[^<]*40#', $html, 'the regular price, struck through');
        self::assertSame(1, substr_count($html, 'data-orillacart-add-to-cart'), 'one buying form: the description comes alone');
        self::assertStringContainsString('id="reviews"', $html);
        self::assertStringContainsString('Lovely.', $html);
        self::assertStringContainsString('itemtype="https://schema.org/BreadcrumbList"', $html);
        self::assertStringContainsString('<a href="#reviews">1 review</a>', $html, 'the stars link to the reviews on the page');
        self::assertSame(1, preg_match_all('#data-orillacart-viewed="' . $this->lamp . '"#', $html), 'marked viewed once');
        self::assertGreaterThanOrEqual(2, substr_count($html, 'data-orillacart-for="' . $this->lamp . '"'), 'the price and the form name the product, for the script');
    }

    public function testATemplateWithoutTheBlocksStillGetsTheBuyingFormAfterTheDescription(): void
    {
        $html = $this->onProductPage(static function (): string {
            $content = apply_filters('the_content', '<p>Warm light for reading.</p>');
            self::assertIsString($content);

            return $content;
        });

        self::assertStringContainsString('Warm light for reading.', $html);
        self::assertSame(1, substr_count($html, 'data-orillacart-add-to-cart'));
        self::assertStringContainsString('data-orillacart-for="' . $this->lamp . '"', $html);
    }

    public function testALandingPageShowsTheProductItsSettingNames(): void
    {
        $html = do_blocks(implode('', [
            '<!-- wp:orillacart/product-title {"productId":' . $this->lamp . ',"level":2,"isLink":true} /-->',
            '<!-- wp:orillacart/product-price {"productId":' . $this->lamp . '} /-->',
            '<!-- wp:orillacart/add-to-cart {"productId":' . $this->lamp . ',"showSku":false} /-->',
            '<!-- wp:orillacart/product-price /-->',
        ]));

        self::assertMatchesRegularExpression('#<h2 class="orillacart-product-title"><a href="[^"]+">Brass lamp</a></h2>#', $html);
        self::assertSame(1, substr_count($html, 'data-orillacart-price'), 'a block without a product shows nothing');
        self::assertStringContainsString('data-orillacart-add-to-cart', $html);
        self::assertStringNotContainsString('LAMP-' . $this->lamp, $html, 'the SKU turned off');
        self::assertStringNotContainsString('data-orillacart-viewed', $html, 'not the product\'s own page');

        wp_update_post(['ID' => $this->lamp, 'post_status' => 'draft']);
        self::assertSame('', trim(do_blocks('<!-- wp:orillacart/product-price {"productId":' . $this->lamp . '} /-->')), 'a draft is not for visitors');
    }

    public function testTheCardTheShopDesignsShowsEachProduct(): void
    {
        $html = do_blocks(<<<'HTML'
            <!-- wp:orillacart/products {"showSearch":false,"showLayouts":false} -->
            <!-- wp:orillacart/product-card -->
            <!-- wp:orillacart/product-image /-->
            <!-- wp:post-title {"isLink":true} /-->
            <!-- wp:orillacart/product-price {"showTiers":false} /-->
            <!-- wp:orillacart/product-button /-->
            <!-- /wp:orillacart/product-card -->
            <!-- /wp:orillacart/products -->
            HTML);

        self::assertStringContainsString('orillacart-product-card--designed', $html);
        self::assertMatchesRegularExpression('#<a href="[^"]+"[^>]*>Brass lamp</a>#', $html, 'the core title block, with the card\'s product');
        self::assertStringContainsString('class="orillacart-sale-badge"', $html);
        self::assertStringContainsString('name="product_id" value="' . $this->lamp . '"', $html, 'the button adds it');
        self::assertStringNotContainsString('orillacart-product-search', $html, 'search turned off');
        self::assertStringNotContainsString('orillacart-layouts', $html);
        self::assertStringContainsString('orillacart-products__count', $html);
        self::assertStringNotContainsString('orillacart-products__count', do_blocks('<!-- wp:orillacart/products {"showCount":false} /-->'));
        self::assertStringContainsString('orillacart-product-card__name', do_blocks('<!-- wp:orillacart/products /-->'), 'without a card, the shop\'s own');
    }

    public function testTheShopsListReadsAsManyTimesForTwelveCardsAsForThree(): void
    {
        $this->actAs('administrator');
        $made = [];
        $queries = [];
        foreach ([2, 11] as $more) {
            for ($i = \count($made); $i < $more; ++$i) {
                $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => "Lamp {$i}", 'post_excerpt' => 'Warm.', 'post_status' => 'publish']);
                self::assertIsInt($id);
                $made[] = $id;
                self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => (string) (10 + $i), 'stock_mode' => 'always_available'], nonce: true)->get_status());
                wp_set_object_terms($id, [$this->category], ProductCategoryTaxonomy::NAME);
            }
            $runner = self::kernel()->container()->get(JobRunner::class);
            self::assertInstanceOf(JobRunner::class, $runner);
            while ($runner->work(['search'], 100, 'test')->count() > 0);
            // Read as a visitor's page would: nothing cached from saving them.
            wp_set_current_user(0);
            wp_cache_flush();
            global $wpdb;
            \assert($wpdb instanceof \wpdb);
            $before = $wpdb->num_queries;
            $html = do_blocks('<!-- wp:orillacart/products {"perPage":12} /-->');
            $queries[] = $wpdb->num_queries - $before;
            self::assertSame($more + 1, substr_count($html, 'orillacart-product-card__name'), (string) $more);
            $this->actAs('administrator');
        }
        // A card reads nothing of its own: the posts, fields, terms and pictures are read for the whole list.
        self::assertLessThanOrEqual($queries[0] + 3, $queries[1], 'queries for 3 and 12 cards: ' . implode(', ', $queries));
        foreach ($made as $id) {
            wp_delete_post($id, true);
        }
    }

    public function testTheShopsPatternsAreMadeOfBlocksThatExist(): void
    {
        $patterns = [];
        foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
            if (\is_array($pattern['categories'] ?? null) && \in_array('orillacart', $pattern['categories'], true) && \is_string($pattern['name']) && \is_string($pattern['content'])) {
                $patterns[$pattern['name']] = $pattern['content'];
            }
        }
        foreach (['shop-hero', 'category-showcase', 'sale-banner', 'new-arrivals', 'product-spotlight', 'launch-page'] as $name) {
            self::assertArrayHasKey('orillacart/' . $name, $patterns);
        }
        foreach ($patterns as $name => $content) {
            foreach (self::blockNames(parse_blocks($content)) as $block) {
                self::assertTrue(\WP_Block_Type_Registry::get_instance()->is_registered($block), "{$name}: {$block}");
            }
        }
    }

    public function testASingleProductBlockGivesItsProductToTheBlocksInside(): void
    {
        $spotlight = static fn(int $id): string => do_blocks('<!-- wp:orillacart/product {"productId":' . $id . '} --><!-- wp:orillacart/product-title {"level":2} /--><!-- wp:orillacart/add-to-cart /--><!-- /wp:orillacart/product -->');

        $html = $spotlight($this->lamp);
        self::assertStringContainsString('<h2 class="orillacart-product-title">Brass lamp</h2>', $html);
        self::assertStringContainsString('name="product_id" value="' . $this->lamp . '"', $html);
        self::assertSame('', trim($spotlight(0)), 'nothing before a product is chosen');
    }

    public function testAnAddOnsBlockGetsTheProductItRendersFor(): void
    {
        // The test site's add-on (tests/e2e/mu-plugins) registers it.
        self::assertTrue(\WP_Block_Type_Registry::get_instance()->is_registered('orillacart-addon/e2e-savings'));
        self::assertStringContainsString('<p class="e2e-savings">Brass lamp saves 5.00</p>', do_blocks('<!-- wp:orillacart-addon/e2e-savings {"productId":' . $this->lamp . '} /-->'));
        self::assertSame('', trim(do_blocks('<!-- wp:orillacart-addon/e2e-savings /-->')), 'no product, nothing');
        $card = do_blocks('<!-- wp:orillacart/products --><!-- wp:orillacart/product-card --><!-- wp:orillacart-addon/e2e-savings /--><!-- /wp:orillacart/product-card --><!-- /wp:orillacart/products -->');
        self::assertStringContainsString('Brass lamp saves 5.00', $card, 'in the shop\'s card');
    }

    /**
     * Renders as the product's page would.
     *
     * @param \Closure(): string $render
     */
    private function onProductPage(\Closure $render): string
    {
        global $wp_query, $wp_the_query, $post;
        $wp_query = new \WP_Query(['p' => $this->lamp, 'post_type' => ProductPostType::NAME]);
        $wp_the_query = $wp_query;
        $wp_query->the_post();
        try {
            return $render();
        } finally {
            wp_reset_postdata();
            $wp_query = $wp_the_query = new \WP_Query();
            $post = null;
        }
    }

    /**
     * @param array<mixed> $blocks as parse_blocks() gives them
     *
     * @return list<string> the names of the blocks and those inside them
     */
    private static function blockNames(array $blocks): array
    {
        $names = [];
        foreach ($blocks as $block) {
            if (\is_array($block) && \is_string($block['blockName'] ?? null)) {
                $names = [...$names, $block['blockName'], ...self::blockNames(\is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [])];
            }
        }

        return $names;
    }

    private function clean(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        foreach (self::TABLES as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
