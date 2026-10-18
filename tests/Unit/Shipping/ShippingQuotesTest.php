<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Geography\Domain\UnknownState;
use OrillaCart\Shipping\Application\CarrierService;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingClass;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Application\ShippingClassService;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryCarrierRepository;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryShippingClassRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ShippingQuotesTest extends TestCase
{
    private CarrierService $carriers;
    private ShippingQuotes $quotes;
    private ShippingClassService $classes;
    private RecordingDispatcher $events;

    protected function setUp(): void
    {
        $records = new InMemoryCarrierRepository();
        $geography = new GeographyService(new InMemoryCountryRepository());
        $classes = new InMemoryShippingClassRepository();
        $this->carriers = new CarrierService($records, $geography, new TaxGroupService(new InMemoryTaxGroupRepository(), new InMemoryTaxSettingsRepository(), $geography, new RecordingDispatcher()), $classes);
        $this->quotes = new ShippingQuotes($records, $classes);
        $this->events = new RecordingDispatcher();
        $this->classes = new ShippingClassService($classes, $records, $this->events);
    }

    public function testRangesIncludeBothEnds(): void
    {
        $this->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Light', '5', weight: new SaveRange('0', '2')),
            new SaveShippingRate('Heavy', '9', weight: new SaveRange('2')),
        ]));

        self::assertSame(['Light'], $this->offered('BG', [new ParcelItem(3, weight: '0.5')]));
        self::assertSame(['Light', 'Heavy'], $this->offered('BG', [new ParcelItem(4, weight: '0.5')]), 'exactly 2 kg: both ends count');
        self::assertSame(['Heavy'], $this->offered('BG', [new ParcelItem(1, weight: '2.0001')]));
    }

    public function testRegionsAreCountriesOrStates(): void
    {
        $this->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Bulgaria', '5', regions: ['BG']),
            new SaveShippingRate('California', '7', regions: ['US-CA']),
            new SaveShippingRate('Everywhere', '25'),
        ]));

        self::assertSame(['Bulgaria', 'Everywhere'], $this->offered('BG'));
        self::assertSame(['California', 'Everywhere'], $this->offered('US', state: 'CA'));
        self::assertSame(['Everywhere'], $this->offered('US', state: 'NY'));
        self::assertSame(['Everywhere'], $this->offered('US'), 'a state rate needs the state');
        self::assertSame(['Everywhere'], $this->offered(null), 'without an address only rates for everywhere');
    }

    public function testAParcelAddsUpEveryUnitThatShips(): void
    {
        $this->carriers->create(new SaveCarrier('Post', rates: [
            new SaveShippingRate('Small', '3.5', length: new SaveRange('0', '50')),
            new SaveShippingRate('Bulky', '30', volume: new SaveRange('50000')),
            new SaveShippingRate('Per item', '2', perItem: true),
            new SaveShippingRate('Big orders', '0', subtotal: new SaveRange('100')),
        ]));
        $book = new ParcelItem(3, '0.5', '20', '15', '3');
        $lamp = new ParcelItem(1, '2.5', '30', '30', '50');

        $options = $this->quotes->options(new ShippingQuery('BG', null, [$book, $lamp], '63.33'));

        // Length 3 × 20 + 30 = 90; volume 3 × 900 + 45000 = 47700; four units.
        self::assertSame([['Per item', '8']], array_map(static fn(ShippingOptionView $option): array => [$option->name, $option->price], $options));
        self::assertSame(['Small', 'Per item'], $this->offered('BG', [new ParcelItem(2, '0.5', '20', '15', '3')]), 'two books are 40 long');
        self::assertSame(['Bulky', 'Per item', 'Big orders'], $this->offered('BG', [new ParcelItem(2, '2.5', '30', '30', '50')], '100'));
    }

    public function testInactiveCarriersAndTheirOrderCount(): void
    {
        $this->carriers->create(new SaveCarrier('Post', position: 2, rates: [new SaveShippingRate('Post', '3')]));
        $courier = $this->carriers->create(new SaveCarrier('Courier', position: 1, rates: [new SaveShippingRate('Courier', '5')]));
        $this->carriers->create(new SaveCarrier('Closed', false, rates: [new SaveShippingRate('Closed', '1')]));

        self::assertSame(['Courier', 'Post'], $this->offered('BG'));

        $this->carriers->update($courier->id, new SaveCarrier('Courier', false, 1, [new SaveShippingRate('Courier', '5', id: $courier->rates[0]->id)]));
        self::assertSame(['Post'], $this->offered('BG'));
    }

    public function testRatesAreSyncedByIdAndChecked(): void
    {
        $carrier = $this->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('A', '1'), new SaveShippingRate('B', '2')]));
        [$a, $b] = $carrier->rates;

        $updated = $this->carriers->update($carrier->id, new SaveCarrier('Courier', rates: [new SaveShippingRate('B2', '2.5', id: $b->id), new SaveShippingRate('C', '3')]));
        self::assertSame([$b->id, 'B2', '2.5'], [$updated->rates[0]->id, $updated->rates[0]->name, $updated->rates[0]->price]);
        self::assertSame('C', $updated->rates[1]->name);
        self::assertCount(2, $updated->rates, "rate {$a->id} is gone");

        $this->expectException(ShippingRuleViolation::class);
        $this->carriers->update($carrier->id, new SaveCarrier('Courier', rates: [new SaveShippingRate('A', '1', id: $a->id)]));
    }

    public function testRegionsMustExist(): void
    {
        $this->expectException(UnknownState::class);

        $this->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Nowhere', '1', regions: ['US-ZZ'])]));
    }

    public function testRangesCannotRunBackwards(): void
    {
        $this->expectException(ShippingRuleViolation::class);

        $this->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Odd', '1', weight: new SaveRange('5', '2'))]));
    }

    public function testARateCarriesOnlyItsShippingClasses(): void
    {
        $bulky = $this->classes->create(new SaveShippingClass('Bulky'))->id;
        $fragile = $this->classes->create(new SaveShippingClass('Fragile'))->id;
        $this->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Standard', '5', shippingClasses: [0, $fragile]),
            new SaveShippingRate('Freight', '40', shippingClasses: [$bulky]),
            new SaveShippingRate('Anything', '60'),
        ]));

        self::assertSame(['Standard', 'Anything'], $this->offered('BG', [new ParcelItem(1, '1')]), 'no class');
        self::assertSame(['Standard', 'Anything'], $this->offered('BG', [new ParcelItem(1, '1'), new ParcelItem(1, '1', shippingClassId: $fragile)]));
        self::assertSame(['Freight', 'Anything'], $this->offered('BG', [new ParcelItem(1, '1', shippingClassId: $bulky)]));
        self::assertSame(['Anything'], $this->offered('BG', [new ParcelItem(1, '1'), new ParcelItem(1, '1', shippingClassId: $bulky)]), 'one rate must carry every item');
        self::assertSame(['Standard', 'Anything'], $this->offered('BG', [new ParcelItem(1, '1', shippingClassId: 99)]), 'a class that is gone counts as none');
    }

    public function testAClassARateCarriesStaysUntilTheRateLetsGo(): void
    {
        $bulky = $this->classes->create(new SaveShippingClass('Bulky'))->id;
        $carrier = $this->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Freight', '40', shippingClasses: [$bulky])]));

        try {
            $this->classes->delete($bulky);
            self::fail('The class is in use.');
        } catch (ShippingRuleViolation $violation) {
            self::assertSame('orillacart_shipping_class_in_use', $violation->errorCode);
        }
        $this->carriers->update($carrier->id, new SaveCarrier('Courier', rates: [new SaveShippingRate('Freight', '40')]));
        $this->classes->delete($bulky);
        self::assertSame([], $this->classes->classes());
        self::assertCount(1, $this->events->events, 'products forget it');

        $this->expectException(ShippingRuleViolation::class);
        $this->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Freight', '40', shippingClasses: [$bulky])]));
    }

    public function testARateWithPostcodesWaitsForAPostcodeThatMatches(): void
    {
        $this->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Sofia', '3', regions: ['BG'], postcodes: ['1000-1999']),
            new SaveShippingRate('Bulgaria', '5', regions: ['BG']),
        ]));
        $offered = fn(?string $postcode): array => array_map(
            static fn(ShippingOptionView $option): string => $option->name,
            $this->quotes->options(new ShippingQuery('BG', null, [new ParcelItem(1, '1')], '10', $postcode)),
        );

        self::assertSame(['Bulgaria'], $offered(null), 'no postcode yet');
        self::assertSame(['Sofia', 'Bulgaria'], $offered('1404'));
        self::assertSame(['Bulgaria'], $offered('4000'));

        $this->expectException(ShippingRuleViolation::class);
        $this->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Wrong', '1', postcodes: ['1999-1000'])]));
    }

    public function testShippingClassNamesAreUnique(): void
    {
        $this->classes->create(new SaveShippingClass('Bulky'));
        $this->expectException(ShippingRuleViolation::class);
        $this->classes->create(new SaveShippingClass(' Bulky '));
    }

    /**
     * @param list<ParcelItem> $items
     *
     * @return list<string>
     */
    private function offered(?string $country, array $items = [], string $subtotal = '10', ?string $state = null): array
    {
        $items = $items === [] ? [new ParcelItem(1, '1')] : $items;

        return array_map(static fn(ShippingOptionView $option): string => $option->name, $this->quotes->options(new ShippingQuery($country, $state, $items, $subtotal)));
    }
}
