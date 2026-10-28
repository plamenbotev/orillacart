<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\I18n\FakeContentLanguages;
use PHPUnit\Framework\TestCase;

/**
 * Design 51: the post with the shop's details is the product; its translations are its text in other languages.
 */
final class ProductTranslationsTest extends TestCase
{
    private TestShop $shop;
    private FakeContentLanguages $languages;
    private ProductTranslations $translations;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-28 12:00:00 UTC');
        $this->languages = new FakeContentLanguages();
        $this->translations = new ProductTranslations($this->languages, $this->shop->productRecords, $this->shop->content);
    }

    public function testATranslationIsTakenBackToThePostWithTheShopsDetails(): void
    {
        // The product was written in German first: the original need not be in the default language.
        $this->shop->content->add(20, 'Hemd');
        $this->shop->content->add(21, 'Shirt');
        $this->shop->products->save(20, new SaveProduct('10'));
        $this->languages->translate(['de' => 20, 'en' => 21]);

        self::assertSame(20, $this->translations->original(21));
        self::assertSame(20, $this->translations->original(20));
        self::assertSame([20, 20, 7], $this->translations->originals([21, 20, 7]));
        self::assertTrue($this->translations->isTranslation(21));
        self::assertFalse($this->translations->isTranslation(20));
    }

    public function testTheProductShowsInTheTranslationOfTheRequestsOrTheLocalesLanguage(): void
    {
        $this->shop->content->add(30, 'Hemd');
        $this->shop->products->save(30, new SaveProduct('10'));
        $this->shop->content->add(31, 'Shirt');
        $this->shop->content->add(32, 'Chemise', published: false);
        $this->languages = new FakeContentLanguages([
            new Language('de', 'de_DE', 'Deutsch'),
            new Language('en', 'en_US', 'English'),
            new Language('fr', 'fr_FR', 'Français'),
        ]);
        $this->languages->translate(['de' => 30, 'en' => 31, 'fr' => 32]);
        $translations = new ProductTranslations($this->languages, $this->shop->productRecords, $this->shop->content);

        self::assertSame(30, $translations->shown(30), 'no language for the request');
        $this->languages->current = 'en';
        self::assertSame(31, $translations->shown(30));
        self::assertSame(31, $translations->shown(31), 'asked for by the translation itself');
        self::assertSame(30, $translations->shown(30, 'de_DE'), 'an email in the customer\'s language');
        $this->languages->current = 'fr';
        self::assertSame(30, $translations->shown(30), 'a draft translation is not shown');
    }

    public function testACartLineIsTheOriginalsWhicheverLanguageItWasAddedIn(): void
    {
        $this->shop->content->add(40, 'Hemd');
        $this->shop->content->add(41, 'Shirt');
        $this->shop->products->save(40, new SaveProduct('10', stockMode: 'always_available'));
        $this->shop->contentLanguages->languages = $this->languages->languages;
        $this->shop->contentLanguages->translate(['de' => 40, 'en' => 41]);
        $this->shop->contentLanguages->current = 'en';

        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(41))->newToken);
        $lines = $this->shop->carts->add($owner, new AddToCart(40))->cart->lines;

        self::assertSame([[40, 2, 'Shirt']], array_map(static fn($line): array => [$line->productId, $line->quantity, $line->name], $lines));
    }

    public function testWithoutTranslationsEveryPostIsItself(): void
    {
        $translations = new ProductTranslations(new FakeContentLanguages([]), $this->shop->productRecords, $this->shop->content);

        self::assertSame(5, $translations->original(5));
        self::assertSame(5, $translations->shown(5));
        self::assertFalse($translations->multilingual());
    }
}
