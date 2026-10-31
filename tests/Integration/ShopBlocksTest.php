<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryThumbnail;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionStorefrontSettingsRepository;

/**
 * The shop-wide blocks on a page: the store notice from the storefront's settings, the brands, the wishlist, and
 * the free shipping progress a visitor's page leaves to the script.
 */
final class ShopBlocksTest extends RestTestCase
{
    private const array TABLES = ['jobs', 'failed_jobs', 'search_values', 'search_variants', 'search_documents', 'stock_levels', 'products'];

    private mixed $kept;
    /** @var list<int> */
    private array $posts = [];
    /** @var array<string, int> by name */
    private array $brands = [];
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kept = get_option(OptionStorefrontSettingsRepository::OPTION);
        $this->clean();
        $this->suffix = substr(uniqid(), -6);
        $this->actAs('administrator');
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        foreach ($this->brands as $brand) {
            wp_delete_term($brand, ProductBrandTaxonomy::NAME);
        }
        update_option(OptionStorefrontSettingsRepository::OPTION, $this->kept);
        $this->clean();
        parent::tearDown();
    }

    public function testTheStoreNoticeIsTheShopsAndShownWhileOnWithText(): void
    {
        self::assertSame('', do_blocks('<!-- wp:orillacart/store-notice /-->'), 'none yet');

        $saved = $this->request('PUT', '/storefront-settings', ['notice' => true, 'notice_text' => 'Free delivery <b>this week</b>', 'notice_link' => '/sale', 'notice_link_label' => 'See the offers', 'notice_dismissible' => false], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        self::assertSame('Free delivery <b>this week</b>', $this->data($saved)['notice_text']);
        $html = do_blocks('<!-- wp:orillacart/store-notice /-->');
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('Free delivery &lt;b&gt;this week&lt;/b&gt;', $html, 'plain text');
        self::assertStringContainsString('href="' . esc_url(home_url('/sale')) . '"', $html, 'a page of this site');
        self::assertStringNotContainsString(' hidden', $html, 'one shoppers may not close shows without the script');
        self::assertStringNotContainsString('data-orillacart-store-notice-dismiss', $html);

        $this->request('PUT', '/storefront-settings', ['notice' => true, 'notice_text' => 'Free delivery', 'notice_dismissible' => true], nonce: true);
        $html = do_blocks('<!-- wp:orillacart/store-notice /-->');
        self::assertMatchesRegularExpression('#data-orillacart-store-notice="[0-9a-f]{16}" hidden#', $html, 'the script shows it unless the shopper closed it');
        self::assertStringContainsString('aria-label="Dismiss this notice"', $html);

        self::assertSame(422, $this->request('PUT', '/storefront-settings', ['notice' => true, 'notice_text' => 'Sale', 'notice_link' => 'javascript:alert(1)'], nonce: true)->get_status());
        $this->request('PUT', '/storefront-settings', ['notice' => false, 'notice_text' => 'Free delivery'], nonce: true);
        self::assertSame('', do_blocks('<!-- wp:orillacart/store-notice /-->'), 'switched off');
    }

    public function testTheBrandsAreListedByNameOrAsChosenWithTheirLogos(): void
    {
        $this->brand('Zeta', products: 1);
        $this->brand('Acme', products: 2);
        $this->brand('Empty', products: 0);

        self::assertSame(['Acme', 'Zeta'], $this->names(''), 'by name; one without products left out');
        self::assertSame(['Acme', 'Empty', 'Zeta'], $this->names('{"showEmpty":true}'));
        self::assertSame(['Zeta', 'Acme'], $this->names('{"source":"chosen","include":[' . $this->brands['Zeta'] . ',' . $this->brands['Acme'] . ',999999]}'), 'in the order chosen');
        self::assertStringContainsString('2 products', $this->render('{"showCount":true}'));
        self::assertStringContainsString('orillacart-brands--list', $this->render('{"layout":"list"}'));
        self::assertStringNotContainsString('orillacart-brand__no-image', $this->render(''), 'without a logo among them, names alone');
        $link = get_term_link($this->brands['Acme'], ProductBrandTaxonomy::NAME);
        self::assertIsString($link);
        self::assertStringContainsString(esc_url($link), $this->render(''));

        $logo = self::factoryImage();
        update_term_meta($this->brands['Acme'], CategoryThumbnail::META, $logo);
        $html = $this->render('');
        self::assertStringContainsString('orillacart-brand__image', $html);
        self::assertStringContainsString('orillacart-brand__no-image', $html, 'Zeta has none: an empty frame');
        wp_delete_attachment($logo, true);
    }

    public function testTheWishlistFollowsTheShopsPolicy(): void
    {
        $this->request('PUT', '/storefront-settings', ['wishlist' => 'off'], nonce: true);
        self::assertSame('', do_blocks('<!-- wp:orillacart/wishlist /-->'));

        $this->request('PUT', '/storefront-settings', ['wishlist' => 'guests'], nonce: true);
        $html = do_blocks('<!-- wp:orillacart/wishlist {"columns":3,"showTitle":false} /-->');
        self::assertStringContainsString('data-orillacart-wishlist-list', $html);
        self::assertStringContainsString('--orillacart-columns: 3', $html);
        self::assertStringNotContainsString('<h2', $html);
        self::assertStringNotContainsString('data-orillacart-wishlist-login', $html);

        $this->request('PUT', '/storefront-settings', ['wishlist' => 'accounts'], nonce: true);
        self::assertStringContainsString('data-orillacart-wishlist-login hidden', do_blocks('<!-- wp:orillacart/wishlist /-->'), 'the script asks a visitor to log in');
    }

    public function testTheFreeShippingProgressOfAVisitorIsTheScriptsToFillIn(): void
    {
        wp_set_current_user(0);
        $html = do_blocks('<!-- wp:orillacart/free-shipping-progress /-->');
        self::assertMatchesRegularExpression('#<div class="orillacart-free-shipping-progress" data-orillacart-free-shipping aria-live="polite" hidden></div>#', $html, 'a cached page carries no one\'s cart');

        $response = $this->request('GET', '/cart/free-shipping');
        self::assertSame(200, $response->get_status());
        self::assertSame(['html' => ''], $this->data($response), 'an empty cart has nothing to say');
        self::assertSame('no-store, private', $response->get_headers()['Cache-Control'] ?? null);
    }

    private function render(string $attributes): string
    {
        return do_blocks('<!-- wp:orillacart/product-brands ' . ($attributes === '' ? '' : $attributes . ' ') . '/-->');
    }

    /**
     * @return list<string> the names shown, without this test's suffix
     */
    private function names(string $attributes): array
    {
        preg_match_all('#class="orillacart-brand__name">([^<]+)<#', $this->render($attributes), $found);

        return array_values(array_filter(array_map(fn(string $name): string => str_replace(' ' . $this->suffix, '', $name), $found[1]), fn(string $name): bool => \in_array($name, ['Acme', 'Empty', 'Zeta'], true)));
    }

    private function brand(string $name, int $products): void
    {
        $term = wp_insert_term("{$name} {$this->suffix}", ProductBrandTaxonomy::NAME);
        self::assertIsArray($term);
        $this->brands[$name] = $term['term_id'];
        for ($i = 0; $i < $products; ++$i) {
            $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => "{$name} {$i}", 'post_status' => 'publish']);
            self::assertIsInt($id);
            $this->posts[] = $id;
            self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '10', 'stock_mode' => 'always_available'], nonce: true)->get_status());
            wp_set_object_terms($id, [$this->brands[$name]], ProductBrandTaxonomy::NAME);
        }
    }

    /** A small image in the media library. */
    private static function factoryImage(): int
    {
        $upload = wp_upload_bits('orillacart-logo-' . uniqid() . '.png', null, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        self::assertFalse($upload['error']);
        $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Logo', 'post_status' => 'inherit'], $upload['file']);
        self::assertIsInt($id);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));

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
