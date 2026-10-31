<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * The blocks a theme places apart from the Products block (sort, count, pages, active filters): they list what
 * that block on the page lists, keep the shopper's choices, and add no search of their own.
 */
final class ListingBlocksTest extends RestTestCase
{
    private const array TABLES = ['jobs', 'failed_jobs', 'search_values', 'search_variants', 'search_documents', 'stock_levels', 'products'];
    private const string PAGE = '<!-- wp:orillacart/products-sort /--><!-- wp:orillacart/products-count /--><!-- wp:orillacart/active-filters /-->'
        . '<!-- wp:orillacart/products {"perPage":1,"showSort":false,"showCount":false,"showPagination":false} /--><!-- wp:orillacart/products-pagination /-->';

    /** @var list<int> */
    private array $posts = [];
    private int $page;
    private int $term;
    private string $slug;
    private mixed $requestUri;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->requestUri = $_SERVER['REQUEST_URI'] ?? null;
        $this->actAs('administrator');
        $term = wp_insert_term('Lamps ' . uniqid(), ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->term = $term['term_id'];
        $found = get_term($this->term);
        self::assertInstanceOf(\WP_Term::class, $found);
        $this->slug = $found->slug;
        foreach (['Desk lamp', 'Floor lamp'] as $title) {
            $post = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => 'publish']);
            self::assertIsInt($post);
            $this->posts[] = $post;
            self::assertSame(200, $this->request('PUT', "/products/{$post}", ['price' => '10', 'stock_mode' => 'always_available'], nonce: true)->get_status());
            wp_set_object_terms($post, [$this->term], ProductCategoryTaxonomy::NAME);
        }
        $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Lights ' . uniqid(), 'post_content' => self::PAGE]);
        self::assertIsInt($page);
        $this->page = $page;
        wp_set_current_user(0);
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['search'], 100, 'test')->count() > 0);
    }

    protected function tearDown(): void
    {
        foreach ([...$this->posts, $this->page] as $post) {
            wp_delete_post($post, true);
        }
        wp_delete_term($this->term, ProductCategoryTaxonomy::NAME);
        $_GET = [];
        if ($this->requestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->requestUri;
        }
        $this->show(new \WP_Query());
        $this->clean();
        parent::tearDown();
    }

    public function testTheBlocksAroundTheProductsListWhatItLists(): void
    {
        $this->show(new \WP_Query(['page_id' => $this->page]));
        $_SERVER['REQUEST_URI'] = "/?page_id={$this->page}&in_stock=1&sort=name";
        $_GET = ['in_stock' => '1', 'sort' => 'name'];
        $html = do_blocks(self::PAGE);

        self::assertStringContainsString('data-orillacart-region="products-count"', $html);
        self::assertStringContainsString('Showing 1–1 of 2', $html, 'the Products block\'s page of one');
        self::assertStringContainsString('<option value="name" selected>', $html, 'the sort chosen');
        self::assertStringContainsString('<input type="hidden" name="in_stock" value="1">', $html, 'sorting keeps the filters');
        self::assertStringNotContainsString('id="orillacart-product-sort"', $html, 'the Products block\'s own sort is off');
        self::assertStringNotContainsString('orillacart-products__count', $html);
        self::assertSame(1, substr_count($html, 'class="orillacart-pagination"'), 'only the pagination block\'s pages');
        self::assertStringContainsString('href="' . htmlspecialchars(ShopListing::pageUrl(2)) . '" rel="next"', $html);
        self::assertStringContainsString('aria-label="Remove In stock only"', $html);
        self::assertStringContainsString('class="orillacart-active-filters__clear" href="' . htmlspecialchars(remove_query_arg('in_stock', ShopListing::pageUrl(1))) . '"', $html, 'clearing keeps the sort');
    }

    public function testACategoryChosenIsAChipButNotOnTheCategorysOwnPage(): void
    {
        $this->show(new \WP_Query(['page_id' => $this->page]));
        $_SERVER['REQUEST_URI'] = "/?page_id={$this->page}&category={$this->slug}";
        $_GET = ['category' => $this->slug];
        $html = do_blocks('<!-- wp:orillacart/active-filters /-->');
        $term = get_term($this->term);
        self::assertInstanceOf(\WP_Term::class, $term);
        self::assertStringContainsString('Category: ' . esc_html($term->name), $html);

        $this->show(new \WP_Query([ProductCategoryTaxonomy::NAME => $this->slug]));
        $_SERVER['REQUEST_URI'] = "/product-category/{$this->slug}/";
        $_GET = [];
        self::assertStringNotContainsString('orillacart-active-filters__chip', do_blocks('<!-- wp:orillacart/active-filters /-->'), 'the page is the category\'s');
        self::assertStringContainsString('2 products', do_blocks('<!-- wp:orillacart/products-count /-->'), 'the shop page\'s Products block, there for the category');
    }

    public function testTheCountAndPagesAddNoSearch(): void
    {
        $this->show(new \WP_Query(['page_id' => $this->page]));
        $_SERVER['REQUEST_URI'] = "/?page_id={$this->page}";
        $_GET = [];
        do_blocks('<!-- wp:orillacart/products {"perPage":1} /-->');
        $searches = [];
        $watch = static function (string $sql) use (&$searches): string {
            if (str_contains($sql, 'orillacart_search_documents')) {
                $searches[] = $sql;
            }

            return $sql;
        };
        add_filter('query', $watch);
        try {
            $html = do_blocks('<!-- wp:orillacart/products-count /--><!-- wp:orillacart/products-pagination /-->');
        } finally {
            remove_filter('query', $watch);
        }

        self::assertStringContainsString('Showing 1–1 of 2', $html);
        self::assertSame([], $searches, 'the page\'s search counted them');
    }

    public function testNothingWithoutAProductsBlock(): void
    {
        $this->show(new \WP_Query());
        foreach (['products-sort', 'products-count', 'products-pagination', 'active-filters'] as $block) {
            self::assertSame('', do_blocks("<!-- wp:orillacart/{$block} /-->"), $block);
        }
    }

    private function show(\WP_Query $query): void
    {
        global $wp_query, $wp_the_query, $post;
        $wp_query = $wp_the_query = $query;
        // No post left from the page before: the blocks look there for the Products block.
        $post = null;
        if ($query->is_singular()) {
            $query->the_post();
        }
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
