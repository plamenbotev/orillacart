<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryLayout;
use OrillaCart\Jobs\Application\JobRunner;

/**
 * The shop's products as a grid of cards or a list of rows: the shopper's choice in the address, else the
 * category's (or the one above it), else the block's.
 */
final class ProductLayoutsTest extends RestTestCase
{
    private const array TABLES = ['jobs', 'failed_jobs', 'search_values', 'search_variants', 'search_documents', 'stock_levels', 'products'];

    private int $post;
    /** @var list<int> */
    private array $terms = [];
    private string $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->actAs('administrator');
        $suffix = substr(uniqid(), -6);
        $parent = wp_insert_term("Tools {$suffix}", ProductCategoryTaxonomy::NAME);
        self::assertIsArray($parent);
        $child = wp_insert_term("Saws {$suffix}", ProductCategoryTaxonomy::NAME, ['parent' => max(0, $parent['term_id'])]);
        self::assertIsArray($child);
        $this->terms = [$child['term_id'], $parent['term_id']];
        $this->child = "saws-{$suffix}";
        $post = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Hand saw', 'post_excerpt' => 'Cuts wood along and across the grain.', 'post_status' => 'publish']);
        self::assertIsInt($post);
        $this->post = $post;
        self::assertSame(200, $this->request('PUT', "/products/{$post}", ['price' => '10', 'stock_mode' => 'always_available'], nonce: true)->get_status());
        wp_set_object_terms($post, [$child['term_id']], ProductCategoryTaxonomy::NAME);
        wp_set_current_user(0);
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['search'], 100, 'test')->count() > 0);
    }

    protected function tearDown(): void
    {
        wp_delete_post($this->post, true);
        foreach ($this->terms as $term) {
            wp_delete_term($term, ProductCategoryTaxonomy::NAME);
        }
        $_GET = [];
        $this->clean();
        parent::tearDown();
    }

    public function testTheShopperTheCategoryOrTheBlockChoosesGridOrList(): void
    {
        $_GET = ['category' => $this->child];
        self::assertSame('grid', $this->layout('<!-- wp:orillacart/products /-->'));
        self::assertSame('list', $this->layout('<!-- wp:orillacart/products {"layout":"list"} /-->'), 'the block\'s');

        update_term_meta($this->terms[1], CategoryLayout::META, 'list');
        self::assertSame('list', $this->layout('<!-- wp:orillacart/products /-->'), 'Tools\' holds for Saws');
        update_term_meta($this->terms[0], CategoryLayout::META, 'grid');
        self::assertSame('grid', $this->layout('<!-- wp:orillacart/products {"layout":"list"} /-->'), 'Saws\' own');

        $_GET = ['category' => $this->child, 'view' => 'list'];
        $html = do_blocks('<!-- wp:orillacart/products /-->');
        self::assertSame('list', $this->layout('<!-- wp:orillacart/products /-->'), 'the shopper\'s');
        self::assertStringContainsString('Cuts wood along and across the grain.', $html, 'a row has its short description');
        self::assertStringContainsString('<input type="hidden" name="view" value="list">', $html, 'searching keeps it');
        self::assertMatchesRegularExpression('#view=grid[^"]*">Grid</a>#', $html);
        self::assertStringContainsString('<input type="hidden" name="view" value="list">', do_blocks('<!-- wp:orillacart/product-filters /-->'), 'and the filters block');
    }

    private function layout(string $block): string
    {
        $html = do_blocks($block);
        self::assertStringContainsString('Hand saw', $html);

        return str_contains($html, 'class="orillacart-products__list"') ? 'list' : 'grid';
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
