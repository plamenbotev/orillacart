<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Jobs\Application\JobRunner;

/**
 * Design 74: an extension lists the shop's products as shoppers find them, with no permission: the shop's own
 * search, sorts, pages, filters' counts and cards; drafts and what is behind a password never; and a landing
 * page's route keeps a visitor's answer.
 */
final class CatalogListTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "landing" { name: "Landing" }
        route GET "/products" { who: public, cache: 60 } fn(request) {
          return shop.catalog.list({ category_id: request.query.category, sort: "price_asc", page: request.query.page ?? 1, per_page: 2, facets: true, cards: true })
        }
        OCS;

    /** @var list<int> */
    private array $posts = [];
    private int $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $term = wp_insert_term('Landing ' . uniqid(), ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->term = $term['term_id'];
        foreach ([['Lamp C', '30', 'publish', ''], ['Lamp A', '10', 'publish', ''], ['Lamp B', '20', 'publish', ''], ['Lamp draft', '5', 'draft', ''], ['Lamp secret', '6', 'publish', 'hush']] as [$title, $price, $status, $password]) {
            $post = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => $status, 'post_password' => $password]);
            self::assertIsInt($post);
            $this->posts[] = $post;
            self::assertSame(200, $this->request('PUT', "/products/{$post}", ['price' => $price, 'stock_mode' => 'always_available'], nonce: true)->get_status());
            wp_set_object_terms($post, [$this->term], ProductCategoryTaxonomy::NAME);
        }
        $this->request('DELETE', '/extensions/landing', nonce: true);
        self::assertSame(201, $this->request('POST', '/extensions', ['id' => 'landing', 'name' => 'Landing', 'files' => ['main.ocs' => self::SCRIPT]], nonce: true)->get_status());
        $enabled = $this->request('POST', '/extensions/landing/enable', ['grant' => []], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));
        $GLOBALS['wp_rest_server'] = null;
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['search'], 100, 'test')->count() > 0);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->actAs('administrator');
        $this->request('DELETE', '/extensions/landing', nonce: true);
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        wp_delete_term($this->term, ProductCategoryTaxonomy::NAME);
        parent::tearDown();
    }

    public function testAnExtensionListsWhatShoppersFindAsTheShopListsIt(): void
    {
        $first = $this->request('GET', '/x/landing/products?category=' . $this->term);
        self::assertSame(200, $first->get_status(), (string) wp_json_encode($first->get_data()));
        $page = $this->data($first);
        self::assertSame([3, 1, 2, 2], [$page['total'] ?? null, $page['page'] ?? null, $page['pages'] ?? null, $page['per_page'] ?? null], 'the draft and the one behind a password are not a shopper\'s');
        $products = $page['products'] ?? null;
        self::assertIsArray($products);
        self::assertSame(['Lamp A', 'Lamp B'], array_column($products, 'name'), 'cheapest first');
        $lamp = $products[0] ?? null;
        self::assertIsArray($lamp);
        self::assertSame([true, true], [$lamp['for_sale'] ?? null, $lamp['in_stock'] ?? null]);
        self::assertIsString($lamp['price'] ?? null);
        self::assertStringContainsString('10', $lamp['price']);
        self::assertSame(get_permalink($this->posts[1]), $lamp['url'] ?? null);
        $cards = $page['cards'] ?? null;
        self::assertIsString($cards);
        self::assertStringContainsString('Lamp A', $cards, 'the shop\'s own cards, of the page');
        self::assertStringContainsString('Lamp B', $cards);
        self::assertStringNotContainsString('Lamp C', $cards);
        $sorts = $page['sorts'] ?? null;
        self::assertIsArray($sorts);
        self::assertSame('Recommended', $sorts['order'] ?? null);
        self::assertIsArray($page['facets'] ?? null, 'the filters\' counts, asked for');

        $next = $this->data($this->request('GET', '/x/landing/products?page=2&category=' . $this->term));
        $more = $next['products'] ?? null;
        self::assertIsArray($more);
        self::assertSame(['Lamp C'], array_column($more, 'name'));
        self::assertSame('hit', $this->request('GET', '/x/landing/products?category=' . $this->term)->get_headers()['X-OrillaCart-Cache'] ?? null);
    }

    public function testTheGuidesLandingRoutePassesTheCheck(): void
    {
        $guide = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/extension-scripts-guide.md');
        $start = strpos($guide, 'route GET "/products" { who: public, cache: 300 }');
        self::assertIsInt($start);
        $end = strpos($guide, '```', $start);
        self::assertIsInt($end);
        $this->actAs('administrator');
        $checked = $this->data($this->request('POST', '/script/check', ['files' => ['main.ocs' => "extension \"guide\" { name: \"Guide\" }\n" . substr($guide, $start, $end - $start)], 'id' => 'draft'], nonce: true));
        self::assertSame([], $checked['findings'] ?? null);
    }
}
