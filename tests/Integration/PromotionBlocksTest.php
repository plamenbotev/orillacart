<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\Http\SiteCookies;

/**
 * Coupons shown to shoppers, through the REST API, and the promotion blocks that tell of them: the progress a
 * visitor's page leaves to the script and a customer's comes with (a price rule's quantity tier too), and a
 * product's promotions in words.
 */
final class PromotionBlocksTest extends RestTestCase
{
    private const array TABLES = ['price_rules', 'coupon_redemptions','coupons', 'cart_lines', 'carts', 'stock_levels', 'products'];

    /** @var list<int> */
    private array $posts = [];
    private int $lamp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        unset($_COOKIE[CartCookie::NAME]);
        $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Desk lamp', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $this->lamp = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '20', 'stock_mode' => 'always_available'], nonce: true)->get_status());
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[CartCookie::NAME]);
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        $this->clean();
        parent::tearDown();
    }

    public function testACouponIsShownToShoppersWhenAutomaticOrChosen(): void
    {
        $automatic = $this->coupon(['code' => '', 'kind' => 'percent', 'amount' => '10', 'description' => 'Autumn sale', 'automatic' => true]);
        $code = $this->coupon(['code' => 'summer10', 'kind' => 'percent', 'amount' => '10']);
        $shown = $this->coupon(['code' => 'welcome', 'kind' => 'percent', 'amount' => '5', 'advertised' => true]);
        self::assertSame([true, false, true], [$automatic['advertised'], $code['advertised'], $shown['advertised']], 'the default: automatic discounts shown, codes hidden');

        $id = $shown['id'];
        self::assertIsInt($id);
        self::assertTrue($this->data($this->request('GET', "/coupons/{$id}"))['advertised']);
        $hidden = $this->request('PUT', "/coupons/{$id}", ['code' => 'welcome', 'kind' => 'percent', 'amount' => '5', 'advertised' => false], nonce: true);
        self::assertSame([200, false], [$hidden->get_status(), $this->data($hidden)['advertised']]);
        self::assertSame(422, $this->request('PUT', "/coupons/{$id}", ['code' => 'welcome', 'kind' => 'percent', 'amount' => '5', 'translations' => ['name' => ['de_DE' => 'x']]], nonce: true)->get_status(), 'a coupon translates its description only');
    }

    public function testAVisitorsProgressIsTheScriptsToFillInFromTheirCart(): void
    {
        $this->coupon(['code' => '', 'kind' => 'buy_get', 'amount' => '100', 'description' => 'Lamp offer', 'automatic' => true, 'buy_quantity' => 2, 'get_quantity' => 1, 'product_ids' => [$this->lamp]]);
        wp_set_current_user(0);

        $html = do_blocks('<!-- wp:orillacart/promotion-progress {"count":2,"showApplied":false} /-->');
        self::assertStringContainsString('<div class="orillacart-promotion-progress" data-orillacart-promotions data-show-applied="false" data-count="2" aria-live="polite" hidden></div>', $html, 'a cached page carries no one\'s cart');
        $empty = $this->request('GET', '/cart/promotions');
        self::assertSame([200, ['html' => ''], 'no-store, private'], [$empty->get_status(), $this->data($empty), $empty->get_headers()['Cache-Control'] ?? null], 'an empty cart has nothing to say');

        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true));
        $html = $this->html($this->request('GET', '/cart/promotions'));
        self::assertStringContainsString('Add 1 more Desk lamp to get 1 free.', $html);
        self::assertStringContainsString('role="progressbar"', $html);
        self::assertStringContainsString('aria-valuenow="1" aria-valuetext="1 of 2 items"', $html, 'the shop adds the one given');

        $this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true);
        $html = $this->html($this->request('GET', '/cart/promotions'));
        self::assertStringContainsString('data-orillacart-promotion="applied"', $html);
        self::assertMatchesRegularExpression('/Lamp offer — applied, you save 20\.00 EUR\./u', $html);
    }

    public function testACustomersProgressComesWithThePage(): void
    {
        $this->coupon(['code' => 'summer10', 'kind' => 'percent', 'amount' => '10', 'min_subtotal' => '50', 'advertised' => true]);
        $this->actAs('customer');
        $this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true);

        $html = do_blocks('<!-- wp:orillacart/promotion-progress {"showBars":false} /-->');
        self::assertStringContainsString('orillacart-promotion-progress--no-bar', $html);
        self::assertStringNotContainsString(' hidden>', $html);
        self::assertMatchesRegularExpression('/Spend 30\.00 EUR more to get 10% off\. Then use the code <strong class="orillacart-promotion__code">SUMMER10<\/strong>\./u', $html);
    }

    public function testAQuantityTierIsAmongTheProgress(): void
    {
        $rule = $this->request('POST', '/price-rules', ['name' => 'Lamps by two', 'tiers' => [['min_quantity' => 2, 'kind' => 'fixed_price', 'value' => '18']], 'product_ids' => [$this->lamp]], nonce: true);
        self::assertSame(201, $rule->get_status(), (string) wp_json_encode($rule->get_data()));
        wp_set_current_user(0);

        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true));
        $html = $this->html($this->request('GET', '/cart/promotions'));
        self::assertStringContainsString('Add 1 more Desk lamp to pay 18.00 EUR each.', $html);
        self::assertStringContainsString('aria-label="Towards Buy 2 or more: 18.00 EUR each"', $html);
        self::assertStringContainsString('aria-valuenow="1" aria-valuetext="1 of 2 items"', $html);

        $this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true);
        self::assertSame('', $this->html($this->request('GET', '/cart/promotions')), 'the lower price already: nothing to tell of');
    }

    public function testAProductTellsOfItsPromotionsInWords(): void
    {
        $this->coupon(['code' => '', 'kind' => 'buy_get', 'amount' => '100', 'description' => 'Lamp offer', 'automatic' => true, 'buy_quantity' => 2, 'get_quantity' => 1, 'product_ids' => [$this->lamp]]);
        $this->coupon(['code' => 'summer10', 'kind' => 'percent', 'amount' => '10', 'min_subtotal' => '50', 'advertised' => true]);
        $this->coupon(['code' => 'secret', 'kind' => 'percent', 'amount' => '50']);
        $this->coupon(['code' => 'welcome', 'kind' => 'percent', 'amount' => '15', 'first_order_only' => true, 'advertised' => true]);
        wp_set_current_user(0);

        $html = do_blocks("<!-- wp:orillacart/product-promotions {\"productId\":{$this->lamp}} /-->");
        self::assertStringContainsString('Buy 2, get 1 free', $html);
        self::assertStringContainsString('Lamp offer', $html);
        self::assertStringContainsString('10% off on orders of 50.00 EUR or more', $html);
        self::assertStringContainsString('Use the code <strong>SUMMER10</strong>.', $html);
        self::assertStringNotContainsString('SECRET', $html, 'a code the shop does not show');
        self::assertStringNotContainsString('WELCOME', $html, 'a visitor\'s cached page: not one for first orders');

        $this->actAs('customer');
        self::assertStringContainsString('WELCOME', do_blocks("<!-- wp:orillacart/product-promotions {\"productId\":{$this->lamp}} /-->"), 'a customer who never ordered');
        self::assertStringNotContainsString('SUMMER10', do_blocks("<!-- wp:orillacart/product-promotions {\"productId\":{$this->lamp},\"showCodes\":false} /-->"));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<mixed> the coupon as saved
     */
    private function coupon(array $fields): array
    {
        $user = get_current_user_id();
        $this->actAs('administrator');
        $made = $this->request('POST', '/coupons', $fields, nonce: true);
        self::assertSame(201, $made->get_status(), (string) wp_json_encode($made->get_data()));
        wp_set_current_user($user);
        return $this->data($made);
    }

    private function html(\WP_REST_Response $response): string
    {
        self::assertSame(200, $response->get_status());
        $html = $this->data($response)['html'] ?? null;
        self::assertIsString($html);

        return $html;
    }

    private function keepCookie(\WP_REST_Response $response): void
    {
        $header = $response->get_headers()['Set-Cookie'] ?? '';
        self::assertIsString($header);
        self::assertStringStartsWith('orillacart_cart=', $header);
        self::assertStringContainsString('Path=' . SiteCookies::path(), $header);
        $_COOKIE[CartCookie::NAME] = substr($header, \strlen(CartCookie::NAME) + 1, 64);
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (self::TABLES as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_translations WHERE object_type = 'coupon'");
    }
}
