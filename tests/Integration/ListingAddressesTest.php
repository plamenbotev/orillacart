<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Storefront\Application\ShopPagesService;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\ListingAddresses;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\ProductArchives;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionShopPagesRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * One address per listing: a category's products at its own page, laid out as the shop page, its pages in the
 * path, older ?category= and ?product-page= addresses sent on, and narrowed listings kept out of search engines.
 */
final class ListingAddressesTest extends RestTestCase
{
    private int $shop;
    private int $category;
    private string $slug;
    private mixed $stored;
    private mixed $requestUri;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stored = get_option(OptionShopPagesRepository::OPTION);
        $this->requestUri = $_SERVER['REQUEST_URI'] ?? null;
        $shop = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Shop ' . uniqid(), 'post_content' => '<!-- wp:paragraph --><p>Welcome to our shop!</p><!-- /wp:paragraph --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:orillacart/product-filters /--></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:orillacart/products {"filters":false} /--></div><!-- /wp:column --></div><!-- /wp:columns -->']);
        self::assertIsInt($shop);
        $this->shop = $shop;
        update_option(OptionShopPagesRepository::OPTION, ['shop' => $shop]);
        $term = wp_insert_term('Lamps ' . uniqid(), ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->category = $term['term_id'];
        $found = get_term($this->category);
        self::assertInstanceOf(\WP_Term::class, $found);
        $this->slug = $found->slug;
    }

    protected function tearDown(): void
    {
        wp_delete_post($this->shop, true);
        wp_delete_term($this->category, ProductCategoryTaxonomy::NAME);
        $this->stored === false ? delete_option(OptionShopPagesRepository::OPTION) : update_option(OptionShopPagesRepository::OPTION, $this->stored);
        $_GET = [];
        if ($this->requestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->requestUri;
        }
        $this->show(new \WP_Query());
        parent::tearDown();
    }

    public function testTheShopPageSendsACategoryAndAnOldPageNumberToTheirOwnAddresses(): void
    {
        $this->show(new \WP_Query(['page_id' => $this->shop]));
        $_SERVER['REQUEST_URI'] = "/?page_id={$this->shop}&category={$this->slug}&sort=name&product-page=3";
        $link = get_term_link($this->category);
        self::assertIsString($link);
        self::assertSame($link . '?page_id=' . $this->shop . '&sort=name', $this->redirected(), 'the category\'s own page, keeping the sorting');

        $_SERVER['REQUEST_URI'] = "/?page_id={$this->shop}&product-page=2";
        self::assertSame(trailingslashit((string) get_permalink($this->shop)) . '2/?page_id=' . $this->shop, $this->redirected());
    }

    public function testACategorysPagesAreInThePathAndNoneIsMissing(): void
    {
        $query = new \WP_Query([ProductCategoryTaxonomy::NAME => $this->slug, 'paged' => 7]);
        $this->show($query);
        $_SERVER['REQUEST_URI'] = "/product-category/{$this->slug}/page/7/?view=list";

        self::assertSame(7, ShopListing::currentPage());
        $link = get_term_link($this->category);
        self::assertIsString($link);
        self::assertSame($link . 'page/2/?view=list', ShopListing::pageUrl(2));
        self::assertSame($link . '?view=list', ShopListing::pageUrl(1));
        self::assertTrue(apply_filters('pre_handle_404', false, $query), 'WordPress\'s own list of posts has no seventh page');
    }

    public function testNarrowedListingsAndTheShoppersOwnPagesAreNotIndexed(): void
    {
        $this->show(new \WP_Query([ProductCategoryTaxonomy::NAME => $this->slug]));
        $robots = apply_filters('wp_robots', []);
        self::assertIsArray($robots);
        self::assertArrayNotHasKey('noindex', $robots);
        $_GET = ['f' => ['size' => ['m']]];
        $robots = apply_filters('wp_robots', []);
        self::assertIsArray($robots);
        self::assertSame(['noindex' => true, 'follow' => true], array_intersect_key($robots, ['noindex' => 1, 'follow' => 1]));
    }

    public function testACategorysPageLaysOutItsProductsAsTheShopPageDoes(): void
    {
        $this->show(new \WP_Query([ProductCategoryTaxonomy::NAME => $this->slug]));
        ob_start();
        do_action(ProductArchives::CONTENT);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('wp-block-columns', $html, 'the shop page\'s columns');
        self::assertStringContainsString('data-orillacart-region="filters"', $html, 'and its filters block');
        self::assertStringNotContainsString('Welcome to our shop!', $html, 'not the shop page\'s own words');
        self::assertStringNotContainsString('class="orillacart-products__filters" method="get" action="" ', $html);
    }

    private function show(\WP_Query $query): void
    {
        global $wp_query, $wp_the_query;
        $wp_query = $wp_the_query = $query;
        if ($query->is_singular()) {
            $query->the_post();
        }
    }

    /** Where the shop sends the request, or null. */
    private function redirected(): ?string
    {
        $to = null;
        $catch = static function (string $location) use (&$to): string {
            $to = $location;
            // The redirect ends the request: the test stops it here instead.
            if ($location !== '') {
                throw new \RuntimeException('redirected');
            }

            return $location;
        };
        add_filter('wp_redirect', $catch);
        try {
            $container = self::kernel()->container();
            $pages = $container->get(ShopPagesService::class);
            $settings = $container->get(SettingsService::class);
            self::assertInstanceOf(ShopPagesService::class, $pages);
            self::assertInstanceOf(SettingsService::class, $settings);
            new ListingAddresses(new ShopLinks($pages, $settings))->redirect();
        } catch (\RuntimeException) {
        } finally {
            remove_filter('wp_redirect', $catch);
        }

        return $to;
    }
}
