<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryOrder;
use OrillaCart\Jobs\Application\JobRunner;

/**
 * The shop's categories as tiles, and the way down into a category: Wear holds Shirts and Hoodies (one product
 * each) and Socks (none); Lights holds a lamp.
 */
final class ProductCategoriesBlockTest extends RestTestCase
{
    private const array TABLES = ['jobs', 'failed_jobs', 'search_values', 'search_variants', 'search_documents', 'stock_levels', 'products'];

    /** @var list<int> */
    private array $posts = [];
    /** @var array<string, int> by name */
    private array $terms = [];
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->actAs('administrator');
        $this->suffix = substr(uniqid(), -6);
        $this->term('Wear');
        $this->term('Lights');
        $this->term('Shirts', 'Wear');
        $this->term('Hoodies', 'Wear');
        $this->term('Socks', 'Wear');
        update_term_meta($this->terms['Hoodies'], CategoryOrder::META, -1);
        $this->product('Shirt', 'Shirts');
        $this->product('Hoodie', 'Hoodies');
        $this->product('Lamp', 'Lights');
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
        foreach (array_reverse($this->terms) as $term) {
            wp_delete_term($term, ProductCategoryTaxonomy::NAME);
        }
        $_GET = [];
        $this->clean();
        parent::tearDown();
    }

    public function testTheBlockShowsTheTopCategoriesThoseBelowOneOrThoseChosen(): void
    {
        $top = $this->tiles('{"showCount":true}');
        self::assertContains('Wear', $top);
        self::assertContains('Lights', $top);
        self::assertNotContains('Shirts', $top);
        self::assertMatchesRegularExpression('#Wear ' . $this->suffix . '</span>\s*<span class="orillacart-category-tile__count">2 products#', $this->render('{"showCount":true}'), 'with the products of the categories below it');

        self::assertSame(['Hoodies', 'Shirts'], $this->tiles('{"source":"below","parent":' . $this->terms['Wear'] . '}'), 'in the shop\'s order; Socks has no products');
        self::assertSame(['Hoodies', 'Shirts', 'Socks'], $this->tiles('{"source":"below","parent":' . $this->terms['Wear'] . ',"showEmpty":true}'));
        self::assertSame(['Lights', 'Shirts'], $this->tiles('{"source":"chosen","include":[' . $this->terms['Lights'] . ',' . $this->terms['Shirts'] . ',999999]}'), 'in the order chosen');
        self::assertStringNotContainsString('<img', $this->render('{"showImage":false}'));
    }

    public function testOnACategorysPageItShowsThoseBelowItAndNothingWithoutAny(): void
    {
        self::assertSame(['Hoodies', 'Shirts'], $this->onCategoryPage('Wear', fn(): array => $this->tiles('')));
        self::assertContains('Lights', $this->onCategoryPage('Wear', fn(): array => $this->tiles('{"followPage":false}')), 'told not to follow the page');
        self::assertSame('', $this->onCategoryPage('Shirts', fn(): string => $this->render('')));

        $template = get_block_template('orillacart//taxonomy-' . ProductCategoryTaxonomy::NAME);
        self::assertNotNull($template);
        self::assertStringContainsString('wp:orillacart/product-categories', $template->content);
        $tags = get_block_template('orillacart//taxonomy-' . ProductTagTaxonomy::NAME);
        self::assertNotNull($tags);
        self::assertStringNotContainsString('wp:orillacart/product-categories', $tags->content, 'a tag has no categories below it');
    }

    public function testTheShopsLinksLeadIntoTheCategoryChosen(): void
    {
        $wear = 'wear-' . $this->suffix;
        $_GET = ['category' => $wear];
        $html = do_blocks('<!-- wp:orillacart/products /-->');
        self::assertMatchesRegularExpression('#category=' . $wear . '" aria-current="page">Wear#', $html);
        self::assertSame(['Hoodies', 'Shirts'], $this->below($html), 'the row below it');

        $_GET = ['category' => 'shirts-' . $this->suffix];
        $html = do_blocks('<!-- wp:orillacart/products /-->');
        self::assertMatchesRegularExpression('#category=' . $wear . '" aria-current="true">Wear#', $html, 'Wear holds the page');
        self::assertSame(['Hoodies', 'Shirts'], $this->below($html), 'Shirts has none below it: those beside it');
        self::assertMatchesRegularExpression('#aria-current="page">Shirts ' . $this->suffix . '</a>#', $html);
    }

    private function render(string $attributes): string
    {
        return do_blocks('<!-- wp:orillacart/product-categories ' . ($attributes === '' ? '' : $attributes . ' ') . '/-->');
    }

    /**
     * @return list<string> the names on the tiles, without this test's suffix
     */
    private function tiles(string $attributes): array
    {
        preg_match_all('#class="orillacart-category-tile__name">([^<]+)<#', $this->render($attributes), $found);

        return array_map(fn(string $name): string => str_replace(' ' . $this->suffix, '', $name), $found[1]);
    }

    /**
     * @return list<string> the names in the shop's second row of links
     */
    private function below(string $html): array
    {
        preg_match('#<ul class="orillacart-categories__below">(.*?)</ul>#s', $html, $row);
        preg_match_all('#>([^<>]+) ' . $this->suffix . '</a>#', $row[1] ?? '', $found);

        return $found[1];
    }

    /**
     * @template T
     *
     * @param \Closure(): T $render
     *
     * @return T
     */
    private function onCategoryPage(string $name, \Closure $render): mixed
    {
        global $wp_query, $wp_the_query;
        $wp_query = new \WP_Query([ProductCategoryTaxonomy::NAME => strtolower($name) . '-' . $this->suffix]);
        $wp_the_query = $wp_query;
        try {
            return $render();
        } finally {
            $wp_query = $wp_the_query = new \WP_Query();
        }
    }

    private function term(string $name, ?string $parent = null): void
    {
        $term = wp_insert_term("{$name} {$this->suffix}", ProductCategoryTaxonomy::NAME, ['parent' => $parent === null ? 0 : max(0, $this->terms[$parent])]);
        self::assertIsArray($term);
        $this->terms[$name] = $term['term_id'];
    }

    private function product(string $title, string $category): void
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '10', 'stock_mode' => 'always_available'], nonce: true)->get_status());
        wp_set_object_terms($id, [$this->terms[$category]], ProductCategoryTaxonomy::NAME);
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
