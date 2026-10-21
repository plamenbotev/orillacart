<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Settings;

use OrillaCart\Settings\Domain\CurrencySettings;
use OrillaCart\Settings\Domain\OfferedCurrency;
use OrillaCart\Settings\Domain\PriceRounding;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrenciesTest extends TestCase
{
    public function testAnAmountGoesToARateAndBack(): void
    {
        self::assertSame('19.5583', (string) Money::of('10')->at('1.95583'));
        self::assertSame('10', (string) Money::of('19.5583')->from('1.95583'));
        self::assertSame('16280', (string) Money::of('100')->at('162.8'));
    }

    /**
     * @return iterable<string, array{PriceRounding, string, int, string}>
     */
    public static function roundings(): iterable
    {
        yield 'to the decimals' => [PriceRounding::Decimals, '36.663', 2, '36.66'];
        yield 'to whole units' => [PriceRounding::Whole, '36.663', 2, '37'];
        yield 'up to just under the next unit' => [PriceRounding::NinetyNine, '36.10', 2, '36.99'];
        yield 'a whole price stays under itself' => [PriceRounding::NinetyNine, '37', 2, '36.99'];
        yield 'currencies without decimals round whole' => [PriceRounding::NinetyNine, '5421.6', 0, '5422'];
        yield 'nothing stays nothing' => [PriceRounding::Whole, '0', 2, '0'];
        yield 'less than a unit is not made nothing' => [PriceRounding::Whole, '0.30', 2, '0.3'];
    }

    #[DataProvider('roundings')]
    public function testConvertedPricesAreRoundedAsTheShopChose(PriceRounding $rounding, string $price, int $decimals, string $rounded): void
    {
        self::assertSame($rounded, (string) $rounding->round(Money::of($price), $decimals));
    }

    public function testAnOfferedCurrencyConvertsPricesAndOtherAmountsApart(): void
    {
        $dollars = new OfferedCurrency(new Currency('USD'), '1.1', PriceRounding::NinetyNine);

        self::assertSame(['43.99', '44', '40'], [(string) $dollars->price(Money::of('40')), (string) $dollars->amount(Money::of('40')), (string) $dollars->back(Money::of('44'))]);
        self::assertSame('5421', (string) new OfferedCurrency(new Currency('JPY'), '162.6')->amount(Money::of('33.34')), 'yen are whole: 5421.084');
    }

    public function testARateIsAboveNothing(): void
    {
        $this->expectException(RuleViolation::class);
        new OfferedCurrency(new Currency('USD'), '0');
    }

    public function testACurrencyIsOfferedOnce(): void
    {
        $this->expectException(RuleViolation::class);
        new CurrencySettings([new OfferedCurrency(new Currency('USD'), '1.1'), new OfferedCurrency(new Currency('USD'), '1.2')]);
    }
}
