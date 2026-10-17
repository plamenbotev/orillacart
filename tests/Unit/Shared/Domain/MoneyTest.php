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
}
