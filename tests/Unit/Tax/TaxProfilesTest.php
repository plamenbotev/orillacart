<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Tax\Domain\Percentage;
use OrillaCart\Tax\Domain\Profile\TaxProfiles;
use OrillaCart\Tax\Domain\Profile\TaxSystem;
use PHPUnit\Framework\TestCase;

final class TaxProfilesTest extends TestCase
{
    public function testEveryEuCountryHasItsVatRates(): void
    {
        $eu = array_column(array_filter(self::iso()['countries'], static fn(array $country): bool => $country[3]), 0);

        self::assertCount(27, $eu);
        foreach ($eu as $country) {
            $profile = TaxProfiles::of($country);
            self::assertSame(TaxSystem::Vat, $profile->system, $country);
            self::assertNotNull($profile->standard, $country);
            self::assertTrue($profile->pricesIncludeTax, $country);
        }
    }

    public function testReducedRatesAreBelowTheStandardOneHighestFirst(): void
    {
        foreach (TaxProfiles::countries() as $country) {
            $profile = TaxProfiles::of($country);
            $rates = array_map(static fn(Percentage $rate): int => $rate->tenThousandths, $profile->reduced);
            $sorted = $rates;
            rsort($sorted);
            self::assertSame($sorted, $rates, $country);
            self::assertSame(array_unique($rates), $rates, $country);
            foreach ([...$rates, $profile->superReduced?->tenThousandths] as $rate) {
                self::assertTrue($rate === null || $rate < $profile->standard?->tenThousandths, $country);
            }
        }
    }

    public function testRatesInForceOnTheDayTheyWereChecked(): void
    {
        self::assertSame('19', (string) TaxProfiles::of('DE')->standard);
        self::assertSame(['13.5', '10'], array_map(strval(...), TaxProfiles::of('FI')->reduced), 'Finland from 2026');
        self::assertSame(['11'], array_map(strval(...), TaxProfiles::of('RO')->reduced), 'Romania from August 2025');
        self::assertSame('24', (string) TaxProfiles::of('GR')->standard, 'Greece by its ISO code, not the EU’s EL');
        self::assertSame('MwSt.', TaxProfiles::of('DE')->name);
        self::assertSame('TVA non applicable, art. 293 B du CGI.', TaxProfiles::of('FR')->smallBusinessNote);
    }

    public function testCanadaTaxesByProvinceAndHstTakesThePlaceOfGst(): void
    {
        $canada = TaxProfiles::of('CA');

        self::assertNull($canada->standard, 'no rate for the whole country, which would add up with HST');
        self::assertFalse($canada->pricesIncludeTax);
        self::assertSame(self::states('CA'), self::sorted($canada->states()), 'every province and territory');
        self::assertSame([['HST', '13']], self::taxes($canada->taxesOf('ON')));
        self::assertSame([['GST', '5'], ['QST', '9.975']], self::taxes($canada->taxesOf('QC')));
        self::assertSame([['GST', '5'], ['RST', '7']], self::taxes($canada->taxesOf('MB')));
    }

    public function testUsStatesWithASalesTaxHaveTheirBaseRate(): void
    {
        $us = TaxProfiles::of('US');

        self::assertSame(TaxSystem::SalesTax, $us->system);
        self::assertSame([['Sales tax', '7.25']], self::taxes($us->taxesOf('CA')));
        self::assertSame([], $us->taxesOf('OR'), 'Oregon has none');
        self::assertCount(46, $us->states(), '45 states and DC');
        self::assertSame([], array_diff($us->states(), self::states('US')));
    }

    public function testACountryWithoutAProfileIsUnknown(): void
    {
        $profile = TaxProfiles::of('BR');

        self::assertFalse($profile->known());
        self::assertNull($profile->standard);
        self::assertNull($profile->checkedOn);
        self::assertSame([], array_diff(TaxProfiles::countries(), array_column(self::iso()['countries'], 0)), 'profiles are of real countries');
    }

    /**
     * @param list<\OrillaCart\Tax\Domain\Profile\RegionalTax> $taxes
     *
     * @return list<array{string, string}>
     */
    private static function taxes(array $taxes): array
    {
        return array_map(static fn($tax): array => [$tax->name, (string) $tax->percent], $taxes);
    }

    /** @return list<string> */
    private static function states(string $country): array
    {
        return self::sorted(array_column(array_filter(self::iso()['states'], static fn(array $state): bool => $state[0] === $country), 1));
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private static function sorted(array $codes): array
    {
        sort($codes);

        return $codes;
    }

    /** @return array{countries: list<array{string, string, string, bool}>, states: list<array{string, string, string}>} */
    private static function iso(): array
    {
        /** @var array{countries: list<array{string, string, string, bool}>, states: list<array{string, string, string}>} $iso */
        $iso = require __DIR__ . '/../../../migrations/data/iso-3166.php';

        return $iso;
    }
}
