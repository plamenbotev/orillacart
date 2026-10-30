<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\AppliedTaxView;
use OrillaCart\Tax\Application\Dto\ImportTaxRates;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxGroupView;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\Dto\TaxRateView;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A group's rates to and from CSV in WooCommerce's columns; rates for some cities, and rates that leave shipping
 * alone.
 */
final class TaxRatesCsvTest extends TestCase
{
    private TestShop $shop;
    private int $group;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->group = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $this->group));
    }

    public function testAWooCommerceFileImportsAsItIs(): void
    {
        $file = "\u{FEFF}Country code,State code,Postcode / ZIP,City,Rate %,Tax name,Priority,Compound,Shipping,Tax class\n"
            . "US,CA,90001...90099;91*,,7.2500,CA Tax,1,0,1,\n"
            . "US,NY,,NEW YORK; Brooklyn ,8.8750,NY Tax,1,0,0,\n"
            . "CA,QC,,,9.975,QST,2,1,1,reduced-rate\n";

        $group = $this->shop->taxRatesCsv->import($this->group, new ImportTaxRates($file));

        self::assertSame([
            ['US', 'CA', ['90001-90099', '91*'], [], '7.25', 'CA Tax', 1, false, true],
            ['US', 'NY', [], ['NEW YORK', 'BROOKLYN'], '8.875', 'NY Tax', 1, false, false],
            ['CA', 'QC', [], [], '9.975', 'QST', 2, true, true],
        ], array_map(static fn(TaxRateView $rate): array => [$rate->country, $rate->state, $rate->postcodes, $rate->cities, $rate->percent, $rate->name, $rate->priority, $rate->compound, $rate->shipping], $group->rates));
    }

    public function testAFileExcelSavedInWindows1252ReadsRightAndOtherBytesAreRefused(): void
    {
        $group = $this->shop->taxRatesCsv->import($this->group, new ImportTaxRates(mb_convert_encoding("DE,,,München,19,MwSt Bäckerei,1,0,1,\n", 'Windows-1252', 'UTF-8')));
        self::assertSame([['MÜNCHEN'], 'MwSt Bäckerei'], [$group->rates[0]->cities, $group->rates[0]->name]);

        $this->expectException(RuleViolation::class);
        $this->shop->taxRatesCsv->import($this->group, new ImportTaxRates("DE,,,\x81\xFF,19,MwSt,1,0,1,\n"));
    }

    public function testAnExportImportsBackTheSame(): void
    {
        $this->shop->taxGroups->addRate($this->group, new SaveTaxRate('US', 'TX', '6.25', '=Texas', 0, ['75001-75099'], code: 'TX-STATE', cities: ['Dallas'], shipping: false));
        $this->shop->taxGroups->addRate($this->group, new SaveTaxRate('DE', null, '19', 'MwSt'));

        $csv = $this->shop->taxRatesCsv->export($this->group);
        self::assertStringContainsString("'=Texas", $csv, 'what looks like a formula does not run in a spreadsheet');

        $other = $this->shop->taxGroups->create(new SaveTaxGroup('Copy'))->id;
        $copied = $this->shop->taxRatesCsv->import($other, new ImportTaxRates($csv));
        $rows = static fn(TaxGroupView $group): array => array_map(static fn(TaxRateView $rate): array => [$rate->country, $rate->state, $rate->postcodes, $rate->cities, $rate->percent, $rate->name, $rate->priority, $rate->compound, $rate->shipping, $rate->code], $group->rates);
        self::assertSame($rows($this->shop->taxGroups->group($this->group)), $rows($copied));
    }

    public function testNothingIsSavedWhenARowCannotBe(): void
    {
        $this->shop->taxGroups->addRate($this->group, new SaveTaxRate('DE', null, '19', 'MwSt'));
        foreach ([
            "US,CA,,,7.25,CA,1,0,1,\n,,,,5,Everywhere,1,0,1,\n" => 'Row 2: a rate needs its country',
            "US,CA,,,7.25,CA,1,0,1,\nUS,ZZ,,,5,Nowhere,1,0,1,\n" => 'Row 2',
            "US,CA,,,seven,CA,1,0,1,\n" => '"seven" is not a rate',
            "US,CA,,,7.25,CA,1,maybe,1,\n" => '"maybe" is not 1 or 0',
            "US,CA,abc!,,7.25,CA,1,0,1,\n" => 'not a postcode pattern',
            "US,,,,6,US,1,0,1,\nUS,CA,90001,,7.25,CA,1,0,1,\n" => 'Rows 2 and 1 apply at the same places with priority 1',
            "US,CA,,,6,CA,1,0,1,\nUS,CA,90001,,1,LA,1,0,1,\n" => 'Rows 2 and 1',
            "DE,,10115,,1,Berlin,0,0,1,\n" => 'Row 1 and a rate the group has',
            "US,CA,902*,,1,Beverly,1,0,1,\nUS,CA,90210,,2,Hills,1,0,1,\n" => 'Rows 2 and 1 apply at the same places with priority 1',
            "US,CA,90000-90999,,1,LA County,1,0,1,\nUS,CA,902*,,2,Beverly,1,0,1,\n" => 'Rows 2 and 1',
            "US,CA,,Los Angeles,1,LA,1,0,1,\nUS,CA,90001,,2,Zip,1,0,1,\n" => 'Rows 2 and 1',
            "US,CA,,S\u{FFFD}o Jos\u{FFFD},1,SJ,1,0,1,\n" => 'Save it as "CSV UTF-8"',
            "Country code,State code\n" => 'The file has no rates',
        ] as $file => $refusal) {
            try {
                $this->shop->taxRatesCsv->import($this->group, new ImportTaxRates($file));
                self::fail("Imported: {$file}");
            } catch (RuleViolation $refused) {
                self::assertStringContainsString($refusal, $refused->getMessage());
            }
        }
        self::assertCount(1, $this->shop->taxGroups->group($this->group)->rates);

        // Rates of their own places, or of another priority, are not charged twice and import.
        $this->shop->taxRatesCsv->import($this->group, new ImportTaxRates("US,CA,90001,,7.25,LA,1,0,1,\nUS,CA,90002,,7.5,LA2,1,0,1,\nUS,,,,1,Federal,2,0,1,\nUS,CA,91*,Pasadena,1,P,1,0,1,\nUS,CA,91*,Glendale,1,G,1,0,1,\nUS,NY,902*,,1,NY,1,0,1,\n"));
        self::assertCount(7, $this->shop->taxGroups->group($this->group)->rates);
        self::assertCount(3, $this->shop->taxRatesCsv->import($this->group, new ImportTaxRates("BG,,,,20,ДДС,0,0,1,\nUS,TX,,,6.25,TX,0,0,1,\nUS,NY,,,4,NY,0,0,1,\n", replace: true))->rates, 'in place of the rates there');
    }

    public function testACityRateHoldsInItsCityAndARateCanLeaveShippingAlone(): void
    {
        $this->shop->taxGroups->addRate($this->group, new SaveTaxRate('US', 'NY', '4', 'NY State', shipping: false));
        $this->shop->taxGroups->addRate($this->group, new SaveTaxRate('US', 'NY', '4.5', 'NYC', 1, cities: [' new  york ']));
        $at = fn(?string $city, bool $shipping = false): array => array_map(static fn(AppliedTaxView $tax): string => $tax->name, $this->shop->taxCalculator->taxes(new TaxQuery($this->group, new TaxAddress('US', 'NY', '10001', $city), forShipping: $shipping))->taxes);

        self::assertSame(['NY State', 'NYC'], $at('New York'));
        self::assertSame(['NY State'], $at('Buffalo'));
        self::assertSame(['NY State'], $at(null), 'the city not known yet');
        self::assertSame(['NYC'], $at('NEW YORK', true), 'the state leaves shipping alone');
    }
}
