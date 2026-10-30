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
        yield 'a Lithuanian postcode with its country' => ['01100', 'LT-01100', true];
        yield 'in a range' => ['01000-01999', 'LT-01100', true];
        yield 'a Latvian one by its start' => ['10*', 'LV-1050', true];
        yield 'a Luxembourg one, its country one letter' => ['1000-1999', 'L-1009', true];
        yield 'a Moldovan one' => ['2001', 'md-2001', true];
        yield 'a pattern written with the country' => ['LT-01100', '01100', true];
        yield 'not another postcode' => ['01100', 'LT-01101', false];
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
        yield 'the old plugin cast postcodes to numbers; letters are not zero' => ['000-999', 'SW1A', false];
        yield 'a Polish postcode with its dash' => ['10-200', '10200', true];
        yield 'is not a range of 10 to 200' => ['10-200', '150', false];
        yield 'a Polish postcode in a range' => ['10000-10999', '10-200', true];
        yield 'past the range by its second group' => ['10000-10199', '10-200', false];
        yield 'a Japanese postcode' => ['100-0001', '1000001', true];
        yield 'too few digits for the range' => ['10000-19999', '1500', false];
        yield 'a Portuguese postcode by its first group' => ['1000-1999', '1000-001', true];
        yield 'a range without its leading zeros' => ['501-999', '00501', true];
        yield 'is not the postcodes that start with those digits' => ['501-999', '50100', false];
        yield 'ends of different lengths' => ['1000-99999', '01500', true];
        yield 'and past their end' => ['1000-99999', '0999', false];
        yield 'three dots are always a range' => ['10...200', '150', true];
        yield 'a US postcode with its extension' => ['94103-1234', '941031234', true];
    }

    #[DataProvider('postcodes')]
    public function testMatching(string $pattern, string $postcode, bool $matches): void
    {
        self::assertSame($matches, PostcodePattern::of($pattern)->matches($postcode));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function overlapping(): iterable
    {
        yield 'a start and a postcode it takes' => ['902*', '90210', true];
        yield 'a start and one it does not' => ['902*', '90310', false];
        yield 'two starts, one inside the other' => ['9*', '902*', true];
        yield 'two starts apart' => ['SW1*', 'SW2*', false];
        yield 'two ranges that meet' => ['1000-1999', '1999-2999', true];
        yield 'two ranges apart' => ['1000-1999', '2000-2999', false];
        yield 'a range and a start inside it' => ['90000-90999', '902*', true];
        yield 'a range is of whole numbers, not of starts' => ['900-999', '9021*', false];
        yield 'a range and a start outside it' => ['1000-1999', '25*', false];
        yield 'a range and letters' => ['1000-1999', 'SW1*', false];
        yield 'the same postcode written two ways' => ['10-200', '10200', true];
        yield 'two postcodes' => ['1000', '1001', false];
    }

    #[DataProvider('overlapping')]
    public function testOverlapping(string $one, string $two, bool $overlap): void
    {
        self::assertSame([$overlap, $overlap], [PostcodePattern::of($one)->overlaps(PostcodePattern::of($two)), PostcodePattern::of($two)->overlaps(PostcodePattern::of($one))]);
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
        yield 'a backwards range with dots' => ['200...10'];
    }

    #[DataProvider('invalid')]
    public function testWhatIsNotAPattern(string $pattern): void
    {
        $this->expectException(RuleViolation::class);
        PostcodePattern::of($pattern);
    }
}
