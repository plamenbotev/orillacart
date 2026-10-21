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

    public function testProportionsAreExactEvenForLargeAmounts(): void
    {
        self::assertSame('3.3333', (string) Money::of('10')->proportion(Money::of('1'), Money::of('3')));
        self::assertSame('-3.3333', (string) Money::of('-10')->proportion(Money::of('1'), Money::of('3')));
        self::assertSame('0', (string) Money::of('10')->proportion(Money::of('1'), Money::zero()));
        // 2,000,000 × 1,500,000 overflows an integer in ten-thousandths; as floats it lost the last digits.
        self::assertSame('1000000', (string) Money::of('2000000')->proportion(Money::of('1500000'), Money::of('3000000')));
        self::assertSame('-1000000', (string) Money::of('-2000000')->proportion(Money::of('1500000'), Money::of('3000000')));
        self::assertSame('1000000', (string) Money::of('-2000000')->proportion(Money::of('1500000'), Money::of('-3000000')));
        self::assertSame('333333.3333', (string) Money::of('1000000')->proportion(Money::of('1000000.0001'), Money::of('3000000.0003')));
        self::assertSame('66666666666.6666', (string) Money::of('99999999999.9999')->proportion(Money::of('2'), Money::of('3')));
    }

    public function testAmountsStayWithinWhatTheColumnsHold(): void
    {
        self::assertSame('99999999999.9999', (string) Money::of('99999999999.9998')->add(Money::of('0.0001')));
        foreach ([
            static fn(): Money => Money::of('99999999999.9999')->add(Money::of('0.0001')),
            static fn(): Money => Money::of('-99999999999.9999')->subtract(Money::of('1')),
            static fn(): Money => Money::of('50000000000')->times(2),
            static fn(): Money => Money::of('1')->times(\PHP_INT_MAX),
            static fn(): Money => Money::of('99999999999')->proportion(Money::of('3'), Money::of('1')),
        ] as $index => $tooLarge) {
            try {
                $tooLarge();
                self::fail("Case {$index} is too large.");
            } catch (RuleViolation $e) {
                self::assertSame('orillacart_amount_too_large', $e->errorCode);
            }
        }
    }

    public function testPercentagesOffAreFromZeroToAHundred(): void
    {
        self::assertSame([true, true, true, false, false], array_map(static fn(string $value): bool => Money::of($value)->isPercentOff(), ['0', '12.5', '100', '100.0001', '-1']));
    }
}
