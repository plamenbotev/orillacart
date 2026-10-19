<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Twig;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Platform\Twig\ShopExtension;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\CurrencyPosition;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Settings\InMemorySettingsRepository;
use PHPUnit\Framework\TestCase;

final class MoneyFilterTest extends TestCase
{
    public function testAmountsShowExactlyInTheShopsDecimalsAndSign(): void
    {
        $money = $this->money(new StoreSettings(new Currency('EUR'), null, WeightUnit::Kilogram, DimensionUnit::Centimetre, currencySign: '€'));

        self::assertSame('1,234,567.90 €', $money('1234567.895'));
        self::assertSame('10,000,000.00 €', $money('9999999.995'));
        self::assertSame('-0.44 €', $money('-0.435'));
        self::assertSame('0.00 €', $money('0'));
        self::assertSame('12.00 USD', $money('12', 'USD'), 'another currency shows its code');
    }

    public function testWholeAmountsShowNoDecimals(): void
    {
        $money = $this->money(new StoreSettings(new Currency('JPY'), null, WeightUnit::Kilogram, DimensionUnit::Centimetre, currencySign: '¥', currencyPosition: CurrencyPosition::Before));

        self::assertSame('¥1,235', $money('1234.5'));
    }

    /**
     * @return \Closure(string, ?string=): string
     */
    private function money(StoreSettings $settings): \Closure
    {
        $geography = new GeographyService(new InMemoryCountryRepository());
        $extension = new ShopExtension(new SettingsService(new InMemorySettingsRepository($settings), new RecordingDispatcher(), $geography), $geography);

        return static fn(string $amount, ?string $currency = null): string => $extension->money($amount, $currency);
    }
}
