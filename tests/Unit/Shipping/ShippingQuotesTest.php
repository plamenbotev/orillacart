<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Geography\Domain\UnknownState;
use OrillaCart\Shipping\Application\CarrierService;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryCarrierRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ShippingQuotesTest extends TestCase
{
    private CarrierService $carriers;
    private ShippingQuotes $quotes;

    protected function setUp(): void
    {
        $records = new InMemoryCarrierRepository();
        $geography = new GeographyService(new InMemoryCountryRepository());
        $this->carriers = new CarrierService($records, $geography, new TaxGroupService(new InMemoryTaxGroupRepository(), new InMemoryTaxSettingsRepository(), $geography, new RecordingDispatcher()));
        $this->quotes = new ShippingQuotes($records);
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
