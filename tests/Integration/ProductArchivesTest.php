<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Jobs\Application\JobRunner;

/**
 * A product category's, tag's or brand's page shows its products as the shop does, in block and classic themes.
 */
final class ProductArchivesTest extends RestTestCase
{
    private const array TABLES = ['jobs', 'failed_jobs', 'search_values', 'search_variants', 'search_documents', 'stock_levels', 'products'];

    /** @var list<int> */
    private array $posts = [];
    /** @var array<non-empty-string, int> by taxonomy */
    private array $terms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->actAs('administrator');
        $lamp = $this->product('Brass lamp');
        $this->product('Linen shade');
        foreach ([ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME, ProductBrandTaxonomy::NAME] as $taxonomy) {
            $term = wp_insert_term('Archive ' . uniqid(), $taxonomy);
            self::assertIsArray($term);
            $this->terms[$taxonomy] = $term['term_id'];
            wp_set_object_terms($lamp, [$term['term_id']], $taxonomy);
        }
        wp_set_current_user(0);
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['search'], 100, 'test')->count() > 0);
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        foreach ($this->terms as $taxonomy => $term) {
            wp_delete_term($term, $taxonomy);
        }
        $this->clean();
        parent::tearDown();
    }

    public function testEachTermsPageShowsItsProducts(): void
    {
        foreach ($this->terms as $taxonomy => $term) {
            self::assertSame(['Brass lamp'], $this->onTermPage($taxonomy, $term, static fn(): string => do_blocks('<!-- wp:orillacart/products /-->')), $taxonomy);
        }
        self::assertNotNull(get_block_template('orillacart//taxonomy-' . ProductTagTaxonomy::NAME), 'a template for block themes');
    }

    public function testAClassicThemeGetsThePluginsTemplateUnlessItHasItsOwn(): void
    {
        $theme = get_stylesheet();
        switch_theme('twentytwentyone');
        try {
            $template = $this->onTermPage(ProductCategoryTaxonomy::NAME, $this->terms[ProductCategoryTaxonomy::NAME], static fn(): mixed => apply_filters('template_include', 'archive.php'));
            self::assertIsString($template);
            self::assertStringEndsWith('/templates/wordpress/product-archive.php', $template);
            self::assertFileExists($template);
        } finally {
            switch_theme($theme);
        }
        self::assertSame('archive.php', $this->onTermPage(ProductCategoryTaxonomy::NAME, $this->terms[ProductCategoryTaxonomy::NAME], static fn(): mixed => apply_filters('template_include', 'archive.php')), 'a block theme\'s template stands');
    }

    /**
     * What $render returns while WordPress shows the term's page: the names of the products, for HTML.
     *
     * @param \Closure(): mixed $render
     */
    private function onTermPage(string $taxonomy, int $term, \Closure $render): mixed
    {
        global $wp_query, $wp_the_query;
        $wp_query = new \WP_Query(['tax_query' => [['taxonomy' => $taxonomy, 'terms' => [$term]]], 'post_type' => ProductPostType::NAME]);
        $wp_the_query = $wp_query;
        try {
            $result = $render();

            return \is_string($result) && str_contains($result, '<') ? self::names($result) : $result;
        } finally {
            $wp_query = $wp_the_query = new \WP_Query();
        }
    }

    /**
     * @return list<string>
     */
    private static function names(string $html): array
    {
        preg_match_all('#class="orillacart-product-card__name">([^<]+)<#', $html, $matches);

        return $matches[1];
    }

    private function product(string $title): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '10', 'stock_mode' => 'always_available'], nonce: true)->get_status());

        return $id;
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
