<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Platform\WordPress\WordPressLanguages;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Storefront\Application\ShopPagesService;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionLegalSettingsRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionShopPagesRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopPageLanguages;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
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
        // The checkout page is the plugin's; the shop page the owner's own, with their words.
        new OptionShopPagesRepository()->made($checkout);
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

        // A version the owner deleted for good is not made again: not when nothing changed, nor on an update.
        wp_delete_post($ownShop, true);
        wp_delete_post($kasse, true);
        $this->pageLanguages($languages)->changed();
        $this->pageLanguages($languages)->upgrade('1.0.0');
        self::assertSame([], get_posts(['post_type' => 'page', 'post_status' => 'any', 'title' => 'Our shop', 'post__not_in' => [$shop], 'fields' => 'ids']));
        self::assertSame([], get_posts(['post_type' => 'page', 'post_status' => 'any', 'title' => 'Kasse', 'fields' => 'ids']));
    }

    public function testTheShopsOwnPageIsKnownByItsIdNotItsTitle(): void
    {
        // The admin worked in German when the plugin made the checkout page, so it is titled "Kasse" in the
        // site's default language, English; the owner's own cart page is titled as the shop would title it.
        $checkout = $this->page('Kasse', '<!-- wp:orillacart/checkout {"align":"wide"} /-->');
        $cart = $this->page('Cart', '<!-- wp:orillacart/cart /-->');
        update_option(OptionShopPagesRepository::OPTION, ['checkout' => $checkout, 'cart' => $cart]);
        new OptionShopPagesRepository()->made($checkout);
        $languages = new FakeContentLanguages([new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch'), new Language('fr', 'fr_FR', 'Français')]);
        $titles = static fn(string $words, string $text): string => $text === 'Checkout' ? match (determine_locale()) {
            'de_DE' => 'Kasse',
            'fr_FR' => 'Caisse',
            default => $words,
        } : $words;
        add_filter('gettext_orillacart', $titles, 10, 2);
        try {
            $this->pageLanguages($languages)->keep();
        } finally {
            remove_filter('gettext_orillacart', $titles);
        }

        $versions = $languages->postTranslations($checkout);
        $this->made = [...$this->made, ...array_values($versions), ...array_values($languages->postTranslations($cart))];
        self::assertSame(['Kasse', 'publish'], [get_the_title($versions['de']), get_post_status($versions['de'])]);
        self::assertSame(['Caisse', 'publish'], [get_the_title($versions['fr']), get_post_status($versions['fr'])]);
        $ownCart = $languages->postTranslations($cart)['de'] ?? null;
        self::assertIsInt($ownCart);
        self::assertSame(['Cart', 'draft'], [get_the_title($ownCart), get_post_status($ownCart)], 'the owner\'s page, whatever it is titled');
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

    public function testTheReceiptLinkGoesToTheCheckoutInTheOrdersLanguage(): void
    {
        $checkout = $this->page('Checkout', '<!-- wp:orillacart/checkout /-->');
        $kasse = $this->page('Kasse', '<!-- wp:orillacart/checkout /-->');
        update_option(OptionShopPagesRepository::OPTION, ['checkout' => $checkout]);
        $languages = new FakeContentLanguages();
        $languages->translate(['en' => $checkout, 'de' => $kasse]);
        // An order placed in German, in memory; the gateway's return and the admin's request are in English.
        $shop = new TestShop();
        $shop->contentLanguages->languages = $languages->languages;
        $shop->content->add(20, 'Ring');
        $shop->products->save(20, new SaveProduct('100', 'virtual'));
        $shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $shop->languages->locale = 'de_DE';
        $owner = new CartOwner($shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $german = $shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'))->number;
        self::assertSame('de_DE', $shop->orderRecords->find($german)?->locale());
        $container = self::kernel()->container();
        $pages = $container->get(ShopPagesService::class);
        self::assertInstanceOf(ShopPagesService::class, $pages);
        $links = new StorefrontOrderLinks($pages, $shop->receipts, $this->links($languages), $shop->orderRecords, new WordPressLanguages());

        self::assertSame('en_US', determine_locale());
        self::assertSame(get_permalink($kasse), remove_query_arg(StorefrontOrderLinks::RECEIPT_QUERY, $links->receiptUrl($german)));
        self::assertSame('en_US', determine_locale(), 'the request\'s language comes back');
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
