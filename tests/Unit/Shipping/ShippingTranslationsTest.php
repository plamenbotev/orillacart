<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Shared\I18n\Language;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SavePickupLocation;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Translations\Domain\TranslationRuleViolation;
use PHPUnit\Framework\TestCase;

/**
 * Design 51: a carrier's name, its rates' and its pickup places' in the site's other languages, saved with it.
 */
final class ShippingTranslationsTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-29 12:00:00 UTC');
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
    }

    public function testANewRateIsTranslatedInTheSaveThatMakesItAndCheckoutShowsIt(): void
    {
        $view = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Standard', '5', translations: ['de_DE' => 'Normal']),
            new SaveShippingRate('Express', '9'),
        ], translations: ['de_DE' => 'Kurier']));

        self::assertSame(['de_DE' => 'Kurier'], $view->translations);
        self::assertSame([['de_DE' => 'Normal'], []], array_map(static fn($rate): array => $rate->translations, $view->rates));
        self::assertSame([['Courier', 'Standard'], ['Courier', 'Express']], $this->options());

        $this->shop->languageOfRequest->locale = 'de_DE';
        self::assertSame([['Kurier', 'Normal'], ['Kurier', 'Express']], $this->options(), 'a rate without a translation keeps its own name');
    }

    public function testASaveWithoutTranslationsKeepsThemAndRemovedRatesAndPlacesLoseTheirs(): void
    {
        $carrier = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Standard', '5', translations: ['de_DE' => 'Normal']),
            new SaveShippingRate('Express', '9', translations: ['de_DE' => 'Eilig']),
        ], translations: ['de_DE' => 'Kurier']));
        [$standard, $express] = $carrier->rates;

        $after = $this->shop->carriers->update($carrier->id, new SaveCarrier('Courier', rates: [new SaveShippingRate('Standard', '5', id: $standard->id)]));

        self::assertSame(['de_DE' => 'Kurier'], $after->translations);
        self::assertSame(['de_DE' => 'Normal'], $after->rates[0]->translations);
        self::assertSame([], $this->shop->translationRecords->all('shipping_rate', [$express->id]), 'the removed rate\'s are gone');

        $this->shop->carriers->delete($carrier->id);
        self::assertSame([], $this->shop->translationRecords->all('carrier', [$carrier->id]));
        self::assertSame([], $this->shop->translationRecords->all('shipping_rate', [$standard->id]));
    }

    public function testPickupPlacesAreTranslatedByField(): void
    {
        $pickup = $this->shop->carriers->create(new SaveCarrier('Pickup', pickupLocations: [
            new SavePickupLocation('shop', 'The shop', 'Main St 1', 'Mon–Fri 9–18', translations: ['name' => ['de_DE' => 'Der Laden'], 'hours' => ['de_DE' => 'Mo–Fr 9–18']]),
            new SavePickupLocation('depot', 'Depot', 'Dock 4', translations: ['name' => ['de_DE' => 'Lager']]),
        ]));

        self::assertSame(['name' => ['de_DE' => 'Der Laden'], 'hours' => ['de_DE' => 'Mo–Fr 9–18']], $pickup->pickupLocations[0]->translations);
        $this->shop->languageOfRequest->locale = 'de_DE';
        $shop = $this->shop->shippingQuotes->options(new ShippingQuery('BG', null, [new ParcelItem(1, '1')], '10'))[0];
        self::assertSame('Der Laden', $shop->name);
        self::assertSame(['Der Laden', 'Main St 1', 'Mo–Fr 9–18'], [$shop->collectAt?->name, $shop->collectAt?->address, $shop->collectAt?->hours], 'the address has no translation and stays');

        // The depot is closed: its words go with it, the shop's stay.
        $this->shop->carriers->update($pickup->id, new SaveCarrier('Pickup', pickupLocations: [new SavePickupLocation('shop', 'The shop', 'Main St 1', 'Mon–Fri 9–18')]));
        self::assertSame(['pickup.shop.hours', 'pickup.shop.name'], array_keys($this->shop->translationRecords->all('carrier', [$pickup->id])[$pickup->id]));
    }

    public function testATranslationTheShopCannotTakeRefusesTheSave(): void
    {
        try {
            $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Standard', '5', translations: ['fr_FR' => 'Normale'])]));
            self::fail('A language the site does not have was taken.');
        } catch (TranslationRuleViolation $e) {
            self::assertSame('orillacart_translation_language', $e->errorCode);
        }

        self::assertSame([], $this->shop->carriers->carriers(), 'nothing was saved');
    }

    public function testAPlaceFieldMustNameAPlaceKey(): void
    {
        $this->expectException(TranslationRuleViolation::class);

        $this->shop->contentTranslations->check('carrier', ['pickup.Not A Key.name' => ['de_DE' => 'x']]);
    }

    /**
     * @return list<array{string, string}>
     */
    private function options(): array
    {
        return array_map(static fn(ShippingOptionView $option): array => [$option->carrier, $option->name], $this->shop->shippingQuotes->options(new ShippingQuery('BG', null, [new ParcelItem(1, '1')], '10')));
    }
}
