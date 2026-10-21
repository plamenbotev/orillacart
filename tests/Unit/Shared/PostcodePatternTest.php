<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared;

use OrillaCart\Shared\Domain\PostcodePattern;
use OrillaCart\Shared\Domain\RuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostcodePatternTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function postcodes(): iterable
    {
        yield 'the postcode itself' => ['1000', '1000', true];
        yield 'another postcode' => ['1000', '1001', false];
        yield 'case and spaces do not matter' => ['sw1a 1aa', 'SW1A1AA', true];
        yield 'a start' => ['SW1*', 'sw1a 1aa', true];
        yield 'not that start' => ['SW1*', 'SW2A 1AA', false];
        yield 'a range includes its start' => ['1000-1999', '1000', true];
        yield 'and its end' => ['1000-1999', '1999', true];
        yield 'but not past it' => ['1000-1999', '2000', false];
        yield 'a range may be written with a dash' => ['1000–1999', '1500', true];
        yield 'a range reads the digits a postcode starts with' => ['94000-94999', '94103-1234', true];
        yield 'a range takes no letters' => ['1000-1999', 'A1500', false];
        yield 'the old plugin cast postcodes to numbers; letters are not zero' => ['0-999', 'SW1A', false];
    }

    #[DataProvider('postcodes')]
    public function testMatching(string $pattern, string $postcode, bool $matches): void
    {
        self::assertSame($matches, PostcodePattern::of($pattern)->matches($postcode));
    }

    public function testPatternsAreKeptNormalized(): void
    {
        self::assertSame('SW1A*', (string) PostcodePattern::of(' sw1a * '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'a backwards range' => ['1999-1000'];
        yield 'a star inside' => ['S*1'];
        yield 'a range of letters' => ['A-B'];
    }

    #[DataProvider('invalid')]
    public function testWhatIsNotAPattern(string $pattern): void
    {
        $this->expectException(RuleViolation::class);
        PostcodePattern::of($pattern);
    }
}
