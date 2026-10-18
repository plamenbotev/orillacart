<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryOrder;
use OrillaCart\Customers\Infrastructure\WordPress\OptionCustomerSettingsRepository;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionShopPagesRepository;

/**
 * The storefront in a real WordPress: the pages activation makes, the blocks on them as a shopper sees them,
 * the product page's buying form, and the routes only the storefront uses (accounts, session, shop pages).
 */
final class StorefrontTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    /** @var list<int> */
    private array $terms = [];
    private mixed $storedPages;
    private mixed $storedAccounts;
    private int $lamp;
    private int $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        unset($_COOKIE[CartCookie::NAME]);
        $_GET = [];
        // WordPress's login form reads the host, which a command-line run does not have.
        $_SERVER['HTTP_HOST'] ??= 'localhost';
        $this->storedPages = get_option(OptionShopPagesRepository::OPTION);
        $this->storedAccounts = get_option(OptionCustomerSettingsRepository::OPTION);

        $this->actAs('administrator');
        $this->lamp = $this->product('Brass lamp', ['price' => '40', 'sale' => ['price' => '30'], 'stock_mode' => 'always_available', 'weight' => '1']);
        $carrier = $this->request('POST', '/carriers', ['name' => 'Courier', 'rates' => [['name' => 'Bulgaria', 'price' => '5', 'regions' => ['BG']]]], nonce: true);
        self::assertSame(201, $carrier->get_status(), (string) wp_json_encode($carrier->get_data()));
        $rates = $this->data($carrier)['rates'];
        self::assertIsArray($rates);
        self::assertIsArray($rates[0]);
        self::assertIsInt($rates[0]['id']);
        $this->rate = $rates[0]['id'];
        self::assertSame(200, $this->request('PUT', '/payment-methods/bank_transfer', ['name' => 'Bank transfer', 'active' => true, 'receipt_note' => 'IBAN BG00 TEST'], nonce: true)->get_status());
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[CartCookie::NAME]);
        $_GET = [];
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        foreach ($this->terms as $term) {
            wp_delete_term($term, ProductCategoryTaxonomy::NAME);
        }
        update_option(OptionShopPagesRepository::OPTION, $this->storedPages);
        if ($this->storedAccounts === false) {
            delete_option(OptionCustomerSettingsRepository::OPTION);
        } else {
            update_option(OptionCustomerSettingsRepository::OPTION, $this->storedAccounts);
        }
        $user = get_user_by('email', 'grace@example.com');
        if ($user instanceof \WP_User) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user->ID);
        }
        $this->clean();
        parent::tearDown();
    }

    public function testActivationMakesTheShopPagesAndLaterOnesKeepTheShopsChoices(): void
    {
        $pages = $this->pages();
        foreach (['shop' => 'products', 'cart' => 'cart', 'checkout' => 'checkout', 'account' => 'account'] as $part => $block) {
            self::assertIsInt($pages[$part], $part);
            self::assertSame('publish', get_post_status($pages[$part]));
            self::assertStringContainsString("<!-- wp:orillacart/{$block} ", (string) get_post_field('post_content', $pages[$part]));
        }

        // The shop turns the cart page off; a later activation leaves it off.
        $this->actAs('administrator');
        $saved = $this->request('PUT', '/shop-pages', ['cart' => null] + $pages, nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        $this->activate();
        self::assertNull($this->pages()['cart']);

        // A deleted page is made again.
        $deleted = $this->post('page', 'Old account page');
        update_option(OptionShopPagesRepository::OPTION, ['account' => $deleted] + $this->pages());
        wp_delete_post($deleted, true);
        $this->activate();
        $account = $this->pages()['account'];
        self::assertIsInt($account);
        self::assertNotSame($deleted, $account);
        self::assertSame('publish', get_post_status($account));
        $this->posts[] = $account;
    }

    public function testOnlyShopManagersChooseTheShopPages(): void
    {
        self::assertSame(401, $this->request('GET', '/shop-pages')->get_status());
        self::assertSame(401, $this->request('PUT', '/shop-pages', ['cart' => 1], nonce: true)->get_status());
        $this->actAs('editor');
        self::assertSame(403, $this->request('PUT', '/shop-pages', ['cart' => 1], nonce: true)->get_status());

        $this->actAs('administrator');
        $pages = $this->pages();
        $draft = $this->post('page', 'Draft', 'draft');
        self::assertSame('orillacart_unknown_page', $this->data($this->request('PUT', '/shop-pages', ['cart' => $draft] + $pages, nonce: true))['code']);
        self::assertSame('orillacart_unknown_page', $this->data($this->request('PUT', '/shop-pages', ['cart' => $this->lamp] + $pages, nonce: true))['code'], 'a product is not a page');
        self::assertSame('orillacart_page_used_twice', $this->data($this->request('PUT', '/shop-pages', ['cart' => $pages['checkout']] + $pages, nonce: true))['code']);
        self::assertSame($pages, $this->data($this->request('GET', '/shop-pages')));
    }

    public function testTheShopListsPublishedProductsWithTodaysPricesByCategory(): void
    {
        $this->actAs('administrator');
        $mug = $this->product('Mug', ['price' => '12.5', 'stock_mode' => 'always_available', 'attributes' => [['name' => 'Colour', 'options' => [['name' => 'Red']]]]]);
        $draft = $this->product('Secret', ['price' => '1'], 'draft');
        $lamps = $this->category('Lamps', 2);
        $kitchen = $this->category('Kitchen', 1);
        wp_set_object_terms($this->lamp, [$lamps], ProductCategoryTaxonomy::NAME);
        wp_set_object_terms($mug, [$kitchen], ProductCategoryTaxonomy::NAME);
        wp_set_current_user(0);

        $shop = do_blocks('<!-- wp:orillacart/products /-->');
        self::assertStringContainsString('Brass lamp', $shop);
        self::assertStringContainsString('Mug', $shop);
        self::assertStringNotContainsString('Secret', $shop, 'drafts are not for sale');
        self::assertMatchesRegularExpression('#<del[^>]*>40\.00 [A-Z]{3}</del> <ins[^>]*>30\.00 [A-Z]{3}</ins>#', $shop, 'the sale price, with the regular one struck through');
        self::assertStringContainsString('name="product_id" value="' . $this->lamp . '"', $shop, 'a product without choices goes in the cart from the shop');
        self::assertStringNotContainsString('name="product_id" value="' . $mug . '"', $shop, 'one with choices is chosen on its page');
        self::assertLessThan(strpos($shop, '>Lamps<'), strpos($shop, '>Kitchen<'), 'categories by their Order field');

        $_GET['category'] = 'kitchen';
        $kitchenShop = do_blocks('<!-- wp:orillacart/products /-->');
        self::assertStringContainsString('Mug', $kitchenShop);
        self::assertStringNotContainsString('Brass lamp', $kitchenShop);
        unset($draft);
    }

    public function testShoppersSortByPriceAndTheShopsOrderAndTheBlockCanShowOnlyFeaturedOrSaleProducts(): void
    {
        $this->actAs('administrator');
        // The lamp costs 30 (on sale from 40); the vase 35; the cup 20, featured; the shirt from 25 (its variations).
        $vase = $this->product('Vase', ['price' => '35', 'stock_mode' => 'always_available']);
        $cup = $this->product('Cup', ['price' => '20', 'stock_mode' => 'always_available', 'featured' => true]);
        $shirt = $this->product('Shirt', ['price' => '99', 'attributes' => [['name' => 'Size', 'options' => [['name' => 'S'], ['name' => 'L']]]]]);
        $offered = $this->data($this->request('GET', "/products/{$shirt}"))['offered'] ?? null;
        self::assertIsArray($offered);
        self::assertIsArray($offered[0]);
        $options = $offered[0]['options'];
        self::assertIsArray($options);
        foreach ($options as $index => $option) {
            self::assertIsArray($option);
            $this->request('POST', "/products/{$shirt}/variations", ['option_ids' => [$option['id']], 'price' => $index === 0 ? '25' : '45', 'stock_mode' => 'always_available'], nonce: true);
        }
        wp_update_post(['ID' => $vase, 'menu_order' => 1]);
        wp_update_post(['ID' => $cup, 'menu_order' => 2]);
        wp_update_post(['ID' => $this->lamp, 'menu_order' => 3]);
        wp_update_post(['ID' => $shirt, 'menu_order' => 4]);
        wp_set_current_user(0);

        $names = static function (string $html): array {
            preg_match_all('#class="orillacart-product-card__name">([^<]+)<#', $html, $found);

            return $found[1];
        };
        $_GET = ['sort' => 'price_asc'];
        self::assertSame(['Cup', 'Shirt', 'Brass lamp', 'Vase'], $names(do_blocks('<!-- wp:orillacart/products /-->')), 'today\'s price: the sale and the lowest variation count');
        $_GET = ['sort' => 'price_desc'];
        self::assertSame(['Vase', 'Brass lamp', 'Shirt', 'Cup'], $names(do_blocks('<!-- wp:orillacart/products /-->')));
        $_GET = [];
        self::assertSame(['Vase', 'Cup', 'Brass lamp', 'Shirt'], $names(do_blocks('<!-- wp:orillacart/products /-->')), 'the shop\'s own order first');
        self::assertSame(['Cup'], $names(do_blocks('<!-- wp:orillacart/products {"featured":true} /-->')));
        self::assertSame(['Brass lamp'], $names(do_blocks('<!-- wp:orillacart/products {"onSale":true} /-->')));
    }

    public function testAProductPageOffersItsChoicesAndAddsToTheCart(): void
    {
        $this->actAs('administrator');
        $shirt = $this->product('Shirt', ['price' => '20', 'stock_mode' => 'always_available', 'attributes' => [
            ['name' => 'Size', 'required' => true, 'options' => [['name' => 'S'], ['name' => 'XL', 'adjustment' => '2.5']]],
        ]]);
        wp_set_current_user(0);

        $html = $this->productPage($shirt, '<p>Soft cotton.</p>');
        self::assertStringContainsString('<p>Soft cotton.</p>', $html, 'the description stays');
        self::assertStringContainsString('20.00', $html);
        self::assertMatchesRegularExpression('#<select[^>]+name="option_ids"[^>]+required#', $html);
        self::assertStringContainsString('XL (+2.50', $html, 'options show what they add to the price');
        self::assertStringContainsString('data-cart-url="' . get_permalink((int) $this->pages()['cart']) . '"', $html);

        // Without a cart page nothing can be bought.
        update_option(OptionShopPagesRepository::OPTION, ['cart' => null] + $this->pages());
        self::assertStringNotContainsString('data-orillacart-add-to-cart', $this->productPage($shirt, ''));
    }

    public function testTheCheckoutSummaryOffersRatesForThePostcodeOnTheForm(): void
    {
        $this->actAs('administrator');
        $sofia = $this->request('POST', '/carriers', ['name' => 'City courier', 'rates' => [['name' => 'Sofia only', 'price' => '2', 'regions' => ['BG'], 'postcodes' => ['1000-1999']]]], nonce: true);
        self::assertSame(201, $sofia->get_status(), (string) wp_json_encode($sofia->get_data()));
        wp_set_current_user(0);
        $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true));

        $_GET = ['billing_country' => 'BG', 'billing_postcode' => '1404'];
        self::assertStringContainsString('Sofia only', do_blocks('<!-- wp:orillacart/checkout /-->'));
        $_GET = ['billing_country' => 'BG', 'billing_postcode' => '1404', 'shipping_country' => 'BG', 'shipping_postcode' => '4000'];
        self::assertStringNotContainsString('Sofia only', do_blocks('<!-- wp:orillacart/checkout /-->'), 'the shipping address counts');
    }

    public function testAVisitorSeesTheCartChecksOutAndGetsTheReceiptOnTheCheckoutPage(): void
    {
        $added = $this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 2], nonce: true);
        self::assertSame(201, $added->get_status(), (string) wp_json_encode($added->get_data()));
        $this->keepCookie($added);

        $cart = do_blocks('<!-- wp:orillacart/cart /-->');
        self::assertStringContainsString('Brass lamp', $cart);
        self::assertStringContainsString('value="2"', $cart);
        self::assertStringContainsString('60.00', $cart);
        self::assertStringContainsString('href="' . get_permalink((int) $this->pages()['checkout']) . '"', $cart);
        self::assertStringContainsString('>2<', do_blocks('<!-- wp:orillacart/mini-cart /-->'));
        $panel = do_blocks('<!-- wp:orillacart/mini-cart {"showButtons":true} /-->');
        self::assertStringContainsString('2 items in cart.', $panel);
        self::assertMatchesRegularExpression('#<p[^>]+data-orillacart-cart-empty hidden>#', $panel, 'the empty message waits, hidden');
        self::assertStringContainsString('href="' . get_permalink((int) $this->pages()['checkout']) . '"', $panel);

        $_GET = ['billing_country' => 'BG'];
        $checkout = do_blocks('<!-- wp:orillacart/checkout /-->');
        self::assertStringContainsString('value="' . $this->rate . '" checked', $checkout, 'the one way to ship is chosen');
        self::assertStringContainsString('65.00', $checkout, 'items and shipping');
        self::assertStringContainsString('value="bank_transfer"', $checkout);

        $placed = $this->request('POST', '/checkout', [
            'email' => 'ada@example.com',
            'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'],
            'rate_id' => $this->rate,
            'payment' => 'bank_transfer',
        ], nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $order = $this->data($placed);
        self::assertIsString($order['key']);
        self::assertSame(add_query_arg('orillacart_order', $order['key'], (string) get_permalink((int) $this->pages()['checkout'])), $order['receipt_url']);

        $_GET = ['orillacart_order' => $order['key']];
        $receipt = do_blocks('<!-- wp:orillacart/checkout /-->');
        self::assertStringContainsString('Thank you for your order', $receipt);
        self::assertStringContainsString('IBAN BG00 TEST', $receipt, 'how to pay, while the order waits for the money');
        self::assertStringContainsString('Bulgaria', $receipt, 'countries by name');

        $_GET = ['orillacart_order' => str_repeat('0', 32)];
        self::assertStringContainsString('There is no order at this address.', do_blocks('<!-- wp:orillacart/checkout /-->'));
        $_GET = [];
        self::assertStringContainsString('The cart is empty.', do_blocks('<!-- wp:orillacart/checkout /-->'));
        self::assertMatchesRegularExpression('#<div[^>]+data-orillacart-cart-full hidden>#', do_blocks('<!-- wp:orillacart/mini-cart {"showButtons":true} /-->'));
    }

    public function testVisitorsMakeAccountsUnlessTheShopMakesNone(): void
    {
        $page = do_blocks('<!-- wp:orillacart/account /-->');
        self::assertStringContainsString('id="orillacart-login"', $page, "WordPress's login form");
        self::assertStringContainsString('data-orillacart-register', $page);

        $short = $this->request('POST', '/accounts', ['email' => 'grace@example.com', 'password' => 'short', 'first_name' => 'Grace', 'last_name' => 'Hopper'], nonce: true);
        self::assertSame('orillacart_weak_password', $this->data($short)['code']);
        $made = $this->request('POST', '/accounts', ['email' => 'grace@example.com', 'password' => 'correct horse', 'first_name' => 'Grace', 'last_name' => 'Hopper'], nonce: true);
        self::assertSame(201, $made->get_status(), (string) wp_json_encode($made->get_data()));
        self::assertSame('grace@example.com', wp_get_current_user()->user_email, 'logged in to the new account');
        self::assertContains('orillacart_customer', wp_get_current_user()->roles);
        self::assertSame('orillacart_logged_in', $this->data($this->request('POST', '/accounts', ['email' => 'other@example.com', 'password' => 'correct horse', 'first_name' => 'A', 'last_name' => 'B'], nonce: true))['code']);

        $account = do_blocks('<!-- wp:orillacart/account /-->');
        self::assertStringContainsString('You have not ordered anything yet.', $account);
        $_GET = ['order' => '999999'];
        self::assertStringContainsString('There is no such order in your account.', do_blocks('<!-- wp:orillacart/account /-->'));

        wp_set_current_user(0);
        self::assertSame('orillacart_account_exists', $this->data($this->request('POST', '/accounts', ['email' => 'grace@example.com', 'password' => 'correct horse', 'first_name' => 'Grace', 'last_name' => 'Hopper'], nonce: true))['code']);
        update_option(OptionCustomerSettingsRepository::OPTION, ['accounts' => 'disabled']);
        $_GET = [];
        self::assertStringNotContainsString('data-orillacart-register', do_blocks('<!-- wp:orillacart/account /-->'));
        self::assertSame('orillacart_accounts_disabled', $this->data($this->request('POST', '/accounts', ['email' => 'new@example.com', 'password' => 'correct horse', 'first_name' => 'A', 'last_name' => 'B'], nonce: true))['code']);
    }

    public function testTheShopsSettingsDecideHowPricesShowAndWhetherItSells(): void
    {
        $stored = get_option('orillacart_settings');
        try {
            $this->actAs('administrator');
            $settings = $this->data($this->request('GET', '/settings'));
            $saved = $this->request('PUT', '/settings', [
                'currency' => $settings['currency'], 'price_decimals' => 2, 'weight_unit' => $settings['weight_unit'], 'dimension_unit' => $settings['dimension_unit'],
                'shop_country' => null, 'shop_state' => null, 'currency_sign' => '$', 'currency_position' => 'before', 'sell_to' => ['BG'],
            ], nonce: true);
            self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
            $mug = $this->product('Mug', ['price' => '9', 'stock_mode' => 'always_available', 'price_visibility' => 'hidden']);
            wp_set_current_user(0);

            $shop = do_blocks('<!-- wp:orillacart/products /-->');
            self::assertStringContainsString('<ins aria-label="Sale price">$30.00</ins>', $shop, 'the sign before the amount');
            self::assertStringContainsString('Ask us for the price', $shop);
            self::assertStringNotContainsString('name="product_id" value="' . $mug . '"', $shop, 'a hidden price is not sold');
            self::assertSame('orillacart_not_for_sale', $this->data($this->request('POST', '/cart/items', ['product_id' => $mug], nonce: true))['code']);

            $this->keepCookie($this->request('POST', '/cart/items', ['product_id' => $this->lamp], nonce: true));
            $_GET = ['billing_country' => 'BG'];
            $checkout = do_blocks('<!-- wp:orillacart/checkout /-->');
            self::assertSame(2, substr_count($checkout, '<option value="BG"'), 'billing and shipping: only the countries the shop sells to');
            self::assertStringNotContainsString('<option value="DE"', $checkout);

            $this->actAs('administrator');
            $this->request('PUT', '/settings', [
                'currency' => $settings['currency'], 'price_decimals' => 2, 'weight_unit' => $settings['weight_unit'], 'dimension_unit' => $settings['dimension_unit'],
                'shop_country' => null, 'shop_state' => null, 'catalog_only' => true,
            ], nonce: true);
            wp_set_current_user(0);
            self::assertStringContainsString('The shop does not take orders online.', do_blocks('<!-- wp:orillacart/cart /-->'));
            self::assertSame('', do_blocks('<!-- wp:orillacart/mini-cart /-->'));
            self::assertStringNotContainsString('data-orillacart-add-to-cart', do_blocks('<!-- wp:orillacart/products /-->'));
            self::assertSame('orillacart_catalog_only', $this->data($this->request('POST', '/cart/items', ['product_id' => $this->lamp], nonce: true))['code']);
        } finally {
            update_option('orillacart_settings', $stored);
        }
    }

    public function testTheSessionGivesVisitorsANonceThatIsNeverCached(): void
    {
        $session = $this->request('GET', '/session');

        self::assertSame(200, $session->get_status());
        self::assertSame('no-store, private', $session->get_headers()['Cache-Control'] ?? null);
        $nonce = $this->data($session)['nonce'];
        self::assertIsString($nonce);
        self::assertNotFalse(wp_verify_nonce($nonce, 'wp_rest'));
        self::assertSame(201, $this->request('POST', '/cart/items', ['product_id' => $this->lamp], nonce: $nonce)->get_status());
    }

    /**
     * @return array{shop: ?int, cart: ?int, checkout: ?int, account: ?int}
     */
    private function pages(): array
    {
        $stored = get_option(OptionShopPagesRepository::OPTION);
        self::assertIsArray($stored);
        $id = static fn(string $part): ?int => \is_int($stored[$part] ?? null) ? $stored[$part] : null;

        return ['shop' => $id('shop'), 'cart' => $id('cart'), 'checkout' => $id('checkout'), 'account' => $id('account')];
    }

    private function activate(): void
    {
        $lifecycle = self::kernel()->container()->get(PluginLifecycle::class);
        self::assertInstanceOf(PluginLifecycle::class, $lifecycle);
        $lifecycle->activate();
    }

    /**
     * The product's content as its own page shows it.
     */
    private function productPage(int $id, string $content): string
    {
        global $wp_query, $wp_the_query, $post;
        $wp_query = new \WP_Query(['p' => $id, 'post_type' => ProductPostType::NAME]);
        $wp_the_query = $wp_query;
        $wp_query->the_post();
        try {
            $html = apply_filters('the_content', $content);
            self::assertIsString($html);

            return $html;
        } finally {
            wp_reset_postdata();
            $wp_query = $wp_the_query = new \WP_Query();
            $post = null;
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function product(string $title, array $details, string $status = 'publish'): int
    {
        $id = $this->post(ProductPostType::NAME, $title, $status);
        $saved = $this->request('PUT', "/products/{$id}", $details, nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        return $id;
    }

    private function post(string $type, string $title, string $status = 'publish'): int
    {
        $id = wp_insert_post(['post_type' => $type, 'post_title' => $title, 'post_status' => $status]);
        self::assertIsInt($id);
        $this->posts[] = $id;

        return $id;
    }

    private function category(string $name, int $position): int
    {
        $term = wp_insert_term($name, ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->terms[] = $term['term_id'];
        update_term_meta($term['term_id'], CategoryOrder::META, $position);

        return $term['term_id'];
    }

    private function keepCookie(\WP_REST_Response $response): void
    {
        $header = $response->get_headers()['Set-Cookie'] ?? '';
        self::assertIsString($header);
        $_COOKIE[CartCookie::NAME] = substr($header, \strlen(CartCookie::NAME) + 1, 64);
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['download_grants', 'payments', 'payment_methods', 'order_notes', 'order_lines', 'orders', 'cart_lines', 'carts', 'shipping_rates', 'carriers', 'variations', 'products'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
