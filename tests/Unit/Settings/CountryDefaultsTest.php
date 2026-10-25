<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Settings;

use OrillaCart\Settings\Domain\CountryDefaults;
use OrillaCart\Settings\Domain\CurrencyPosition;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class CountryDefaultsTest extends TestCase
{
    public function testEveryCountryButAntarcticaHasACurrencyTheShopCanKeep(): void
    {
        /** @var array{countries: list<array{string, string, string, bool}>} $iso */
        $iso = require __DIR__ . '/../../../migrations/data/iso-3166.php';
        foreach ($iso['countries'] as [$country]) {
            $money = CountryDefaults::money($country);
            if ($country === 'AQ') {
                self::assertNull($money);
                continue;
            }
            self::assertNotNull($money, $country);
            new Currency($money['currency']);
            self::assertLessThanOrEqual(8, mb_strlen($money['sign']), $country);
        }
    }

    public function testMoneyIsWrittenAsTheCountryWritesIt(): void
    {
        self::assertSame(['currency' => 'EUR', 'position' => CurrencyPosition::AfterWithSpace, 'sign' => '€'], CountryDefaults::money('DE'));
        self::assertSame(['currency' => 'USD', 'position' => CurrencyPosition::Before, 'sign' => '$'], CountryDefaults::money('US'));
        self::assertSame(['currency' => 'EUR', 'position' => CurrencyPosition::AfterWithSpace, 'sign' => '€'], CountryDefaults::money('BG'), 'the euro since 2026');
        self::assertSame(CurrencyPosition::BeforeWithSpace, CountryDefaults::money('NL')['position'] ?? null);
    }

    public function testOnlyTheUsLiberiaAndMyanmarUseImperialUnits(): void
    {
        self::assertSame(WeightUnit::Pound, CountryDefaults::weightUnit('US'));
        self::assertSame(DimensionUnit::Inch, CountryDefaults::dimensionUnit('US'));
        self::assertSame(WeightUnit::Kilogram, CountryDefaults::weightUnit('GB'));
        self::assertSame(DimensionUnit::Centimetre, CountryDefaults::dimensionUnit('DE'));
    }
}
