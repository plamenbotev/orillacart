<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\LinkedProducts;
use OrillaCart\Jobs\Application\JobRunner;

/**
 * The products shown beside others: a product's upsells and related products on its page, and the cart's
 * cross-sells, all as the shop's cards.
 */
final class MerchandiseTest extends RestTestCase
{
    private const array TABLES = ['jobs', 'failed_jobs', 'search_values', 'search_variants', 'search_documents', 'cart_lines', 'carts', 'stock_levels', 'products'];

    /** @var list<int> */
    private array $posts = [];
    private int $lighting;
    private int $lamp;
    private int $goldLamp;
    private int $shade;
    private int $bulb;
    private int $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        unset($_COOKIE[CartCookie::NAME]);
        $this->actAs('administrator');
        $term = wp_insert_term('Lighting ' . uniqid(), ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->lighting = $term['term_id'];
        $this->lamp = $this->product('Brass lamp', 1);
        $this->goldLamp = $this->product('Gold lamp', 2);
        $this->shade = $this->product('Linen shade', 3);
        $this->bulb = $this->product('Warm bulb', 4);
        $this->draft = $this->product('Secret lamp', 5, 'draft');
        foreach ([$this->lamp, $this->goldLamp, $this->shade, $this->bulb, $this->draft] as $id) {
            wp_set_object_terms($id, [$this->lighting], ProductCategoryTaxonomy::NAME);
        }

        // As the editor saves them: through the posts API, which keeps the ids each once and drops the product itself.
        $request = new \WP_REST_Request('POST', '/wp/v2/' . ProductPostType::NAME . "/{$this->lamp}");
        $request->set_body_params(['meta' => [LinkedProducts::UPSELLS => [$this->goldLamp, $this->lamp, $this->draft, $this->goldLamp], LinkedProducts::CROSS_SELLS => [$this->bulb]]]);
        self::assertSame(200, rest_do_request($request)->get_status());
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
        wp_delete_term($this->lighting, ProductCategoryTaxonomy::NAME);
        unset($_COOKIE[CartCookie::NAME]);
        $this->clean();
        parent::tearDown();
    }

    public function testAProductsPageOffersItsUpsellsAndProductsOfItsCategory(): void
    {
        self::assertSame([$this->goldLamp, $this->draft], LinkedProducts::upsellsOf($this->lamp));

        $html = $this->productPage($this->lamp);
        $upsells = self::section($html, 'orillacart-upsells');
        self::assertSame(['Gold lamp'], self::names($upsells), 'a draft is not for show');
        self::assertSame(['Linen shade', 'Warm bulb'], self::names(self::section($html, 'orillacart-related')), 'neither the product itself nor its upsells');
        self::assertStringContainsString('data-orillacart-add-to-cart', $upsells, 'bought from the page');

        self::assertSame([], self::names(self::section($this->productPage($this->bulb), 'orillacart-upsells')), 'a product without upsells shows none');
    }

    public function testTheCartOffersTheCrossSellsOfWhatIsInIt(): void
    {
        $added = $this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true);
        self::assertSame(201, $added->get_status(), (string) wp_json_encode($added->get_data()));
        $header = $added->get_headers()['Set-Cookie'] ?? '';
        self::assertIsString($header);
        $_COOKIE[CartCookie::NAME] = substr($header, \strlen(CartCookie::NAME) + 1, 64);

        $cart = do_blocks('<!-- wp:orillacart/cart /-->');
        $crossSells = self::section($cart, 'orillacart-cross-sells');
        self::assertSame(['Warm bulb'], self::names($crossSells));
        self::assertStringNotContainsString('data-orillacart-add-to-cart', $crossSells, 'the form posts, and the page shows the cart with it');

        $this->request('POST', '/cart/items', ['product_id' => $this->bulb, 'quantity' => 1], nonce: true);
        self::assertSame('', self::section(do_blocks('<!-- wp:orillacart/cart /-->'), 'orillacart-cross-sells'), 'not what is in the cart already');
    }

    private function productPage(int $id): string
    {
        global $wp_query, $wp_the_query, $post;
        $wp_query = new \WP_Query(['p' => $id, 'post_type' => ProductPostType::NAME]);
        $wp_the_query = $wp_query;
        $wp_query->the_post();
        try {
            $html = apply_filters('the_content', '');
            self::assertIsString($html);

            return $html;
        } finally {
            wp_reset_postdata();
            $wp_query = $wp_the_query = new \WP_Query();
            $post = null;
        }
    }

    private static function section(string $html, string $class): string
    {
        return preg_match('#<section class="orillacart-product-row ' . $class . '".*?</section>#s', $html, $match) === 1 ? $match[0] : '';
    }

    /**
     * @return list<string>
     */
    private static function names(string $html): array
    {
        preg_match_all('#class="orillacart-product-card__name">([^<]+)<#', $html, $matches);

        return $matches[1];
    }

    private function product(string $title, int $order, string $status = 'publish'): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => $status, 'menu_order' => $order]);
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
