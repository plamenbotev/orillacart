<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Platform\WordPress\WordPressLanguages;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Storefront\Application\ShopPagesService;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionLegalSettingsRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionShopPagesRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopPageLanguages;
use OrillaCart\Tests\Fixtures\I18n\FakeContentLanguages;

/**
 * The shop's pages in each language: a page made before Polylang or WPML gets the default language, and each
 * language without a version of it one, published for the shop's own pages, a draft for the owner's words.
 */
final class ShopPageLanguagesTest extends RestTestCase
{
    /** @var list<int> */
    private array $made = [];
    private mixed $shopPages;
    private mixed $legal;
    private mixed $madePages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopPages = get_option(OptionShopPagesRepository::OPTION);
        $this->legal = get_option(OptionLegalSettingsRepository::OPTION);
        $this->madePages = get_option(OptionShopPagesRepository::MADE_OPTION);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            wp_delete_post($id, true);
        }
        foreach ([OptionShopPagesRepository::OPTION => $this->shopPages, OptionLegalSettingsRepository::OPTION => $this->legal, OptionShopPagesRepository::MADE_OPTION => $this->madePages] as $option => $value) {
            $value === false ? delete_option($option) : update_option($option, $value);
        }
        delete_option(ShopPageLanguages::DONE_OPTION);
        parent::tearDown();
    }

    public function testPagesWithoutALanguageGetTheDefaultAndAVersionInEachOtherLanguage(): void
    {
        $checkout = $this->page('Checkout', '<!-- wp:orillacart/checkout {"align":"wide"} /-->');
        $shop = $this->page('Our shop', '<!-- wp:orillacart/products {"align":"wide"} /-->');
        $terms = $this->page('Terms of sale', '<!-- wp:paragraph --><p>Our terms.</p><!-- /wp:paragraph -->');
        update_option(OptionShopPagesRepository::OPTION, ['shop' => $shop, 'checkout' => $checkout]);
        update_option(OptionLegalSettingsRepository::OPTION, ['terms' => $terms]);
        $languages = new FakeContentLanguages();
        $german = static fn(string $words, string $text): string => determine_locale() === 'de_DE' && $text === 'Checkout' ? 'Kasse' : $words;
        add_filter('gettext_orillacart', $german, 10, 2);
        try {
            $this->pageLanguages($languages)->changed();
        } finally {
            remove_filter('gettext_orillacart', $german);
        }

        self::assertSame('en', $languages->ofPost($checkout));
        $kasse = $this->translation($languages, $checkout);
        self::assertSame(['Kasse', 'publish', '<!-- wp:orillacart/checkout {"align":"wide"} /-->'], [get_the_title($kasse), get_post_status($kasse), get_post_field('post_content', $kasse)]);
        // Removed with the plugin, as the pages it made are.
        self::assertContains($kasse, (array) get_option(OptionShopPagesRepository::MADE_OPTION));
        // The owner's title, and the owner's words, are theirs to translate before the version goes live.
        $ownShop = $this->translation($languages, $shop);
        self::assertSame(['Our shop', 'draft'], [get_the_title($ownShop), get_post_status($ownShop)]);
        $ownTerms = $this->translation($languages, $terms);
        self::assertSame(['Terms of sale', 'draft'], [get_the_title($ownTerms), get_post_status($ownTerms)]);
        self::assertNotContains($ownTerms, (array) get_option(OptionShopPagesRepository::MADE_OPTION));

        // Done until the languages or the pages change: a version the owner deleted is not made again.
        wp_delete_post($ownShop, true);
        $this->pageLanguages($languages)->changed();
        self::assertSame([], get_posts(['post_type' => 'page', 'post_status' => 'any', 'title' => 'Our shop', 'post__not_in' => [$shop], 'fields' => 'ids']));
    }

    public function testEveryLanguagesVersionOfTheCheckoutIsTheCheckout(): void
    {
        $checkout = $this->page('Checkout', '<!-- wp:orillacart/checkout /-->');
        $kasse = $this->page('Kasse', '<!-- wp:orillacart/checkout /-->');
        update_option(OptionShopPagesRepository::OPTION, ['checkout' => $checkout]);
        $languages = new FakeContentLanguages();
        $languages->translate(['en' => $checkout, 'de' => $kasse]);
        $links = $this->links($languages);

        self::assertContains($kasse, $links->pageIds());
        global $wp_query, $wp_the_query;
        $wp_query = $wp_the_query = new \WP_Query(['page_id' => $kasse]);
        try {
            // What keeps caches and search engines away from one shopper's checkout.
            self::assertTrue($links->isPage('checkout'));
        } finally {
            $wp_query = $wp_the_query = new \WP_Query();
        }
        $languages->current = 'de';
        self::assertSame(get_permalink($kasse), $links->pageUrl($checkout));
    }

    private function page(string $title, string $content): int
    {
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content]);
        self::assertIsInt($id);
        $this->made[] = $id;

        return $id;
    }

    private function translation(FakeContentLanguages $languages, int $id): int
    {
        $translation = $languages->postTranslations($id)['de'] ?? null;
        self::assertIsInt($translation);
        $this->made[] = $translation;

        return $translation;
    }

    private function pageLanguages(FakeContentLanguages $languages): ShopPageLanguages
    {
        return new ShopPageLanguages(new OptionShopPagesRepository(), new OptionLegalSettingsRepository($languages), $languages, new WordPressLanguages());
    }

    private function links(FakeContentLanguages $languages): ShopLinks
    {
        $container = self::kernel()->container();
        $pages = $container->get(ShopPagesService::class);
        $settings = $container->get(SettingsService::class);
        $translations = $container->get(ProductTranslations::class);
        self::assertInstanceOf(ShopPagesService::class, $pages);
        self::assertInstanceOf(SettingsService::class, $settings);
        self::assertInstanceOf(ProductTranslations::class, $translations);

        return new ShopLinks($pages, $settings, $translations, $languages);
    }
}
