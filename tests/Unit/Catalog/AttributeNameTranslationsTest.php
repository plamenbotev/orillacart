<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OptionView;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Translations\Domain\TranslationRuleViolation;
use PHPUnit\Framework\TestCase;

/**
 * Design 51: attributes' and options' names in the site's other languages, saved with the product that has them.
 */
final class AttributeNameTranslationsTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-29 12:00:00 UTC');
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
        $this->shop->content->add(10, 'Shirt');
    }

    public function testANewAttributeIsTranslatedInTheSaveThatMakesIt(): void
    {
        $view = $this->shop->products->save(10, $this->shirt(['de_DE' => 'Farbe'], ['de_DE' => 'Rot'], ['de_DE' => 'Blau']));

        $colour = $view->attributes[0];
        self::assertSame(['de_DE' => 'Farbe'], $colour->translations);
        self::assertSame([['de_DE' => 'Rot'], ['de_DE' => 'Blau']], array_map(static fn(OptionView $option): array => $option->translations, $colour->options));
        self::assertSame(['de_DE' => 'Farbe'], $this->shop->products->product(10)->attributes[0]->translations);
    }

    public function testTheShopperReadsTheNamesInTheLanguageShown(): void
    {
        $this->shop->products->save(10, $this->shirt(['de_DE' => 'Farbe'], ['de_DE' => 'Rot'], null));
        $red = $this->shop->products->product(10)->attributes[0]->options[0]->id;

        self::assertSame([['Colour', ['Red', 'Blue']]], $this->names(), 'the default language: the fields themselves');
        $this->shop->languageOfRequest->locale = 'de_DE';
        self::assertSame([['Farbe', ['Rot', 'Blue']]], $this->names(), 'an option without a translation keeps its own name');
        $quoted = $this->shop->catalog->offer(10);
        self::assertSame('Farbe', $quoted->attributes[0]->name);
        $quote = $this->shop->quotes->quote(new QuoteRequest(10, [$red]));
        self::assertSame([['Farbe', 'Rot']], array_map(static fn($option): array => [$option->attribute, $option->name], $quote->options));
    }

    public function testASaveWithoutTranslationsKeepsThemAndRemovingAnOptionForgetsIts(): void
    {
        $this->shop->products->save(10, $this->shirt(['de_DE' => 'Farbe'], ['de_DE' => 'Rot'], ['de_DE' => 'Blau']));
        $saved = $this->shop->products->product(10)->attributes[0];

        // The editor sends the attribute again without its translations, and only the first option.
        $this->shop->products->save(10, new SaveProduct('20', attributes: [new SaveAttribute('Colour', [new SaveOption('Red', id: $saved->options[0]->id)], id: $saved->id)]));

        $after = $this->shop->products->product(10)->attributes[0];
        self::assertSame(['de_DE' => 'Farbe'], $after->translations);
        self::assertSame(['de_DE' => 'Rot'], $after->options[0]->translations);
        self::assertSame([], $this->shop->translationRecords->all('attribute_option', [$saved->options[1]->id]), 'the removed option\'s are gone');

        $this->shop->products->delete(10);
        self::assertSame([], $this->shop->translationRecords->all('attribute', [$saved->id]));
    }

    public function testATranslationTheSiteCannotHaveRefusesTheWholeSave(): void
    {
        try {
            $this->shop->products->save(10, $this->shirt(['fr_FR' => 'Couleur'], null, null));
            self::fail('A language the site does not have was taken.');
        } catch (TranslationRuleViolation $e) {
            self::assertSame('orillacart_translation_language', $e->errorCode);
        }

        self::assertFalse($this->shop->products->product(10)->saved, 'nothing was saved');
    }

    /**
     * @param array<string, string>|null $colour
     * @param array<string, string>|null $red
     * @param array<string, string>|null $blue
     */
    private function shirt(?array $colour, ?array $red, ?array $blue): SaveProduct
    {
        return new SaveProduct('20', attributes: [new SaveAttribute('Colour', [
            new SaveOption('Red', translations: $red),
            new SaveOption('Blue', translations: $blue),
        ], translations: $colour)]);
    }

    /** @return list<array{string, list<string>}> */
    private function names(): array
    {
        return array_map(static fn(AttributeView $attribute): array => [$attribute->name, array_map(static fn(OptionView $option): string => $option->name, $attribute->options)], $this->shop->catalog->offers([10])[10]->attributes);
    }
}
