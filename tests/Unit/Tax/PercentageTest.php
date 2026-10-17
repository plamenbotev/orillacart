<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Domain\Percentage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PercentageTest extends TestCase
{
    #[TestWith(['20', '20', '20.0000'])]
    #[TestWith(['7.25', '7.25', '7.2500'])]
    #[TestWith(['0.375', '0.375', '0.3750'])]
    #[TestWith(['0', '0', '0.0000'])]
    #[TestWith(['999.9999', '999.9999', '999.9999'])]
    #[TestWith(['5.10', '5.1', '5.1000'])]
    public function testParsesDecimalStrings(string $input, string $short, string $fixed): void
    {
        $percent = Percentage::of($input);

        self::assertSame($short, (string) $percent);
        self::assertSame($fixed, $percent->toFixed());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'five decimals' => ['1.23456'];
        yield 'too large' => ['1000'];
        yield 'comma' => ['7,25'];
        yield 'empty' => [''];
        yield 'trailing dot' => ['7.'];
        yield 'exponent' => ['1e2'];
    }

    #[DataProvider('invalid')]
    public function testRefusesAnythingElse(string $input): void
    {
        $this->expectException(RuleViolation::class);

        Percentage::of($input);
    }

    public function testAddsExactly(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 in floats.
        self::assertSame('0.3', (string) Percentage::of('0.1')->add(Percentage::of('0.2')));
        self::assertTrue(Percentage::of('4.375')->equals(Percentage::of('4')->add(Percentage::of('0.375'))));
        self::assertTrue(Percentage::zero()->isZero());
    }
}
