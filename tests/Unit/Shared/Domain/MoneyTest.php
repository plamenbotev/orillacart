<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Domain;

use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAmountsAddUpExactly(): void
    {
        $sum = Money::zero();
        for ($i = 0; $i < 10; ++$i) {
            $sum = $sum->add(Money::of('0.1'));
        }

        self::assertTrue($sum->equals(Money::of('1')));
        self::assertSame('1', (string) $sum);
        self::assertSame('19.9000', Money::of('19.9')->toFixed());
    }

    public function testNegativeAmountsAreAdjustments(): void
    {
        $price = Money::of('5')->add(Money::of('-8'));

        self::assertTrue($price->isNegative());
        self::assertSame('-3', (string) $price);
        self::assertSame('-3.0000', $price->toFixed());
        self::assertTrue($price->atLeastZero()->isZero());
        self::assertSame('-0.5', (string) Money::of('-0.5'));
        self::assertSame('7.5', (string) Money::of('2.5')->times(3));
        self::assertSame('1.25', (string) Money::of('2')->subtract(Money::of('0.75')));
    }

    public function testPercentagesAndRoundingAreExact(): void
    {
        self::assertSame('2.85', (string) Money::of('15')->percent('19'));
        self::assertSame('0.145', (string) Money::of('2')->percent('7.25'));
        // 33.33 × 7.25 % = 2.416425, held to four decimals and rounded half away from zero.
        self::assertSame('2.4164', (string) Money::of('33.33')->percent('7.25'));
        self::assertSame('-0.3', (string) Money::of('-1.5')->percent('20'));
        self::assertSame('0.44', (string) Money::of('0.435')->rounded(2));
        self::assertSame('-0.44', (string) Money::of('-0.435')->rounded(2));
        self::assertSame('0.43', (string) Money::of('0.4349')->rounded(2));
        self::assertSame('3', (string) Money::of('2.5')->rounded(0));
        self::assertSame('8.50', Money::of('8.5')->format(2));
        self::assertSame('9', Money::of('8.5')->format(0));
        self::assertSame('-1.2500', Money::of('-1.25')->format(4));
        self::assertSame(1, Money::of('2')->compare(Money::of('1.9999')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'comma' => ['1,5'];
        yield 'five decimals' => ['0.12345'];
        yield 'too large' => ['123456789012'];
        yield 'plus sign' => ['+1'];
        yield 'words' => ['ten'];
    }

    #[DataProvider('notAmounts')]
    public function testOnlyDecimalsAreAmounts(string $value): void
    {
        $this->expectException(RuleViolation::class);

        Money::of($value);
    }

    public function testTheTaxIncludedInAnAmountComesOutAtEachRate(): void
    {
        self::assertSame('2', (string) Money::of('12')->includedPercent('20', ['20']));
        self::assertSame('10', (string) Money::of('12')->withoutPercents(['20']));
        // 12.00 at 19 %: 1.9160 of VAT, 10.0840 before it.
        self::assertSame(['1.916', '10.084'], [(string) Money::of('12')->includedPercent('19', ['19']), (string) Money::of('12')->withoutPercents(['19'])]);
        // A country's 5 % and a state's 2.5 % add up to 7.5 % of 107.50.
        self::assertSame(['5', '2.5', '100'], [(string) Money::of('107.5')->includedPercent('5', ['5', '2.5']), (string) Money::of('107.5')->includedPercent('2.5', ['5', '2.5']), (string) Money::of('107.5')->withoutPercents(['5', '2.5'])]);
        self::assertSame('9', (string) Money::of('9')->withoutPercents([]));
    }
}
