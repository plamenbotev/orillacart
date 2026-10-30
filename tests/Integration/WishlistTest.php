<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Customers\Infrastructure\WordPress\WordPressAccounts;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionStorefrontSettingsRepository;

/**
 * Wishlists in a real WordPress: a customer's in their account, the cards of any list from the product-cards
 * route, and the account page's wishlist section as the shop's settings say.
 */
final class WishlistTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    private int $lamp;
    private int $shade;
    private int $draft;
    private mixed $stored;

    protected function setUp(): void
    {
        parent::setUp();
        // WordPress's login form reads the host, which a command-line run does not have.
        $_SERVER['HTTP_HOST'] ??= 'localhost';
        $this->stored = get_option(OptionStorefrontSettingsRepository::OPTION);
        delete_option(OptionStorefrontSettingsRepository::OPTION);
        $this->actAs('administrator');
        $this->lamp = $this->product('Brass lamp');
        $this->shade = $this->product('Linen shade');
        $this->draft = $this->product('Secret lamp', 'draft');
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        $customer = get_user_by('login', 'orillacart_subscriber');
        if ($customer instanceof \WP_User) {
            delete_user_meta($customer->ID, WordPressAccounts::key(WordPressAccounts::WISHLIST));
        }
        $this->stored === false ? delete_option(OptionStorefrontSettingsRepository::OPTION) : update_option(OptionStorefrontSettingsRepository::OPTION, $this->stored);
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_products");
        parent::tearDown();
    }

    public function testACustomersWishlistIsInTheirAccount(): void
    {
        self::assertSame(401, $this->request('GET', '/account/wishlist')->get_status(), 'a visitor\'s is in their browser');

        $this->actAs('subscriber');
        self::assertSame([], $this->data($this->request('GET', '/account/wishlist'))['product_ids']);
        self::assertSame([$this->lamp], $this->data($this->request('PUT', "/account/wishlist/{$this->lamp}", nonce: true))['product_ids']);
        $merged = $this->request('POST', '/account/wishlist', ['product_ids' => [$this->shade, $this->lamp]], nonce: true);
        self::assertSame([$this->shade, $this->lamp], $this->data($merged)['product_ids'], 'the browser\'s list joins in front');
        self::assertSame([$this->shade], $this->data($this->request('DELETE', "/account/wishlist/{$this->lamp}", nonce: true))['product_ids']);

        update_option(OptionStorefrontSettingsRepository::OPTION, ['wishlist' => 'off']);
        self::assertSame('orillacart_wishlist_off', $this->data($this->request('GET', '/account/wishlist'))['code']);
    }

    public function testTheCardsOfAListAreThoseOfItsPublishedProductsInOrder(): void
    {
        $cards = $this->data($this->request('GET', "/product-cards?ids={$this->shade},{$this->draft},{$this->lamp},x"));

        self::assertSame([$this->shade, $this->lamp], $cards['ids']);
        self::assertIsString($cards['html']);
        self::assertSame(2, substr_count($cards['html'], '<li class="orillacart-product-card"'));
        self::assertStringNotContainsString('Secret lamp', $cards['html']);
    }

    public function testTheAccountPageHasAWishlistAsTheShopSays(): void
    {
        self::assertStringContainsString('data-orillacart-wishlist-list', do_blocks('<!-- wp:orillacart/account /-->'), 'a visitor\'s, from their browser');

        update_option(OptionStorefrontSettingsRepository::OPTION, ['wishlist' => 'accounts']);
        self::assertStringNotContainsString('data-orillacart-wishlist-list', do_blocks('<!-- wp:orillacart/account /-->'));
        $this->actAs('subscriber');
        $_GET = ['section' => 'wishlist'];
        try {
            self::assertStringContainsString('data-orillacart-wishlist-list', do_blocks('<!-- wp:orillacart/account /-->'));
        } finally {
            $_GET = [];
        }

        update_option(OptionStorefrontSettingsRepository::OPTION, ['wishlist' => 'off']);
        self::assertStringNotContainsString('section=wishlist', do_blocks('<!-- wp:orillacart/account /-->'));
    }

    private function product(string $title, string $status = 'publish'): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => $status]);
        self::assertIsInt($id);
        $this->posts[] = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '10', 'stock_mode' => 'always_available'], nonce: true)->get_status());

        return $id;
    }
}
