<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Translations;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SavePickupLocation;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Jobs\RecordedProgress;
use OrillaCart\Translations\Application\SyncTranslationPackages;
use PHPUnit\Framework\TestCase;

/**
 * Design 51, step 4: the shop's objects as packages of words for WPML's translation management, and what its
 * translators finish taken into the translations table.
 */
final class TranslationPackagesTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-29 12:00:00 UTC');
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch'), new Language('fr', 'fr_FR', 'Français')];
        $this->shop->translationService->active = true;
    }

    public function testASavedCarrierIsAPackageOfItsNamePlacesAndRatesAndGoesWithIt(): void
    {
        $pickup = $this->shop->carriers->create(new SaveCarrier('Pickup', pickupLocations: [new SavePickupLocation('shop', 'The shop', 'Main St 1')]));
        self::assertSame([
            "carrier:{$pickup->id}:name" => 'Pickup',
            "carrier:{$pickup->id}:pickup.shop.name" => 'The shop',
            "carrier:{$pickup->id}:pickup.shop.address" => 'Main St 1',
        ], $this->shop->translationService->words('carrier', $pickup->id), 'empty hours have nothing to translate');
        self::assertSame('Pickup', $this->shop->translationService->packages["carrier:{$pickup->id}"]->title);

        $courier = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Standard', '5')]));
        $rate = $courier->rates[0]->id;
        $this->shop->carriers->update($courier->id, new SaveCarrier('Post', rates: [new SaveShippingRate('Normal', '5', id: $rate)]));
        self::assertSame(["carrier:{$courier->id}:name" => 'Post', "shipping_rate:{$rate}:name" => 'Normal'], $this->shop->translationService->words('carrier', $courier->id), 'its words as they are now');

        $this->shop->carriers->delete($pickup->id);
        $this->shop->carriers->delete($courier->id);
        self::assertSame([], $this->shop->translationService->packages);
    }

    public function testWhatTranslatorsFinishIsTakenAndTheShopsOwnTranslationsOfOtherLanguagesStay(): void
    {
        $carrier = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Standard', '5')], translations: ['fr_FR' => 'Coursier']));
        $rate = $carrier->rates[0]->id;
        $this->shop->translationService->finished = [
            "carrier:{$carrier->id}:name" => ['de_DE' => 'Kurier'],
            "shipping_rate:{$rate}:name" => ['de_DE' => 'Normal', 'fr_FR' => 'Normale'],
        ];

        self::assertSame([2, 0], $this->shop->translationPackages->pull(), 'the carrier and its rate');
        self::assertSame(['name' => ['de_DE' => 'Kurier', 'fr_FR' => 'Coursier']], $this->shop->translationRecords->all('carrier', [$carrier->id])[$carrier->id]);
        self::assertSame(['name' => ['de_DE' => 'Normal', 'fr_FR' => 'Normale']], $this->shop->translationRecords->all('shipping_rate', [$rate])[$rate]);
        self::assertSame([0, 0], $this->shop->translationPackages->pull(), 'nothing new the second time');
    }

    public function testAFixInTheShopStaysUntilTheTranslatorsChangeTheirs(): void
    {
        $carrier = $this->shop->carriers->create(new SaveCarrier('Courier'));
        $this->shop->translationService->finished = ["carrier:{$carrier->id}:name" => ['de_DE' => 'Kurir']];
        $this->shop->translationPackages->pull();

        $this->shop->carriers->update($carrier->id, new SaveCarrier('Courier', translations: ['de_DE' => 'Kurier']));
        self::assertSame([0, 0], $this->shop->translationPackages->pull(), 'WPML still has its older word');
        self::assertSame(['de_DE' => 'Kurier'], $this->carrierName($carrier->id));

        $this->shop->carriers->update($carrier->id, new SaveCarrier('Courier', translations: ['de_DE' => '']));
        $this->shop->translationPackages->pull();
        self::assertSame([], $this->carrierName($carrier->id), 'nor does it come back once the shop removed it');

        $this->shop->translationService->finished = ["carrier:{$carrier->id}:name" => ['de_DE' => 'Paketdienst']];
        self::assertSame([1, 0], $this->shop->translationPackages->pull(), 'a word the translators changed is newer');
        self::assertSame(['de_DE' => 'Paketdienst'], $this->carrierName($carrier->id));
    }

    /**
     * @return array<string, string>
     */
    private function carrierName(int $carrier): array
    {
        return $this->shop->translationRecords->all('carrier', [$carrier])[$carrier]['name'] ?? [];
    }

    public function testATranslationTypedInTheShopGoesToTheTranslatorsUnlessTheyChangedTheirsSince(): void
    {
        $carrier = $this->shop->carriers->create(new SaveCarrier('Courier', translations: ['de_DE' => 'Kurier']));
        $key = "carrier:{$carrier->id}:name";
        self::assertSame([[$key, 'de_DE', 'Kurier']], $this->shop->translationService->given, 'WPML has the shop\'s word');

        // A translator changes it in WPML; the owner saves the carrier for something else before the pull.
        $this->shop->translationService->finished[$key]['de_DE'] = 'Paketdienst';
        $this->shop->carriers->update($carrier->id, new SaveCarrier('Courier', active: false));
        self::assertCount(1, $this->shop->translationService->given, 'their newer word is not overwritten');
        $this->shop->translationPackages->pull();
        self::assertSame(['de_DE' => 'Paketdienst'], $this->carrierName($carrier->id));

        $this->shop->carriers->update($carrier->id, new SaveCarrier('Courier', translations: ['de_DE' => 'Bote']));
        self::assertSame([[$key, 'de_DE', 'Kurier'], [$key, 'de_DE', 'Bote']], $this->shop->translationService->given, 'a word the shop changed since goes');
    }

    public function testATranslationTheShopWouldRefuseIsLeftOut(): void
    {
        $this->shop->content->add(10, 'Shirt');
        $product = $this->shop->products->save(10, new SaveProduct('20', attributes: [new SaveAttribute('Colour', [new SaveOption('Red')])]));
        $red = $product->attributes[0]->options[0]->id;
        self::assertSame("attribute_option:{$red}:name", array_keys($this->shop->translationService->words('product', 10))[1], 'a product\'s own attributes and options');
        $this->shop->translationService->finished = ["attribute_option:{$red}:name" => ['de_DE' => str_repeat('R', 500)]];

        self::assertSame([0, 1], $this->shop->translationPackages->pull());
        self::assertSame([], $this->shop->translationRecords->all('attribute_option', [$red]));
    }

    public function testEveryObjectIsRegisteredAtOnceAndNothingWithoutAService(): void
    {
        $this->shop->translationService->active = false;
        $carrier = $this->shop->carriers->create(new SaveCarrier('Courier'));
        self::assertSame([], $this->shop->translationService->packages);

        $this->shop->translationService->active = true;
        self::assertSame(1, $this->shop->translationPackages->registerAll());
        self::assertArrayHasKey("carrier:{$carrier->id}", $this->shop->translationService->packages);
    }

    public function testEachPackagesJobIsReportedAsItIsDone(): void
    {
        $carrier = $this->shop->carriers->create(new SaveCarrier('Courier'));
        $this->shop->translationService->packages = [];

        $progress = new RecordedProgress();
        self::assertSame([], $this->shop->jobs->get(SyncTranslationPackages::TYPE)->handle([self::job('first', SyncTranslationPackages::REGISTER), self::job('second', SyncTranslationPackages::PULL), self::job('third', SyncTranslationPackages::sendKey('carrier', $carrier->id), ['kind' => 'carrier', 'id' => $carrier->id])], $progress->progress));

        self::assertSame(['first' => 'done', 'second' => 'done', 'third' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'each reported in turn');
        self::assertArrayHasKey("carrier:{$carrier->id}", $this->shop->translationService->packages);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function job(string $id, string $key, array $payload = []): QueuedJob
    {
        return new QueuedJob($id, 'translations', SyncTranslationPackages::TYPE, $key, $payload, 1, 1);
    }
}
