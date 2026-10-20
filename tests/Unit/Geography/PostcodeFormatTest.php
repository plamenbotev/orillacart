<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Geography;

use OrillaCart\Geography\Domain\PostcodeFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostcodeFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function postcodes(): iterable
    {
        yield 'Sofia' => ['BG', '1000', true];
        yield 'five digits in Bulgaria' => ['BG', '10000', false];
        yield 'London, small and spaced' => ['GB', ' sw1a   1aa ', true];
        yield 'London without the space' => ['GB', 'SW1A1AA', true];
        yield 'not British' => ['GB', '12345', false];
        yield 'Berlin' => ['DE', '10115', true];
        yield 'Amsterdam' => ['NL', '1012ab', true];
        yield 'Warsaw without the dash' => ['PL', '00950', false];
        yield 'ZIP+4' => ['US', '10001-1234', true];
        yield 'Ottawa' => ['CA', 'k1a 0b1', true];
        yield 'a country not listed' => ['AE', 'anything', true];
    }

    #[DataProvider('postcodes')]
    public function testACountrysPostcodesLookTheWayItWritesThem(string $country, string $postcode, bool $fits): void
    {
        self::assertSame($fits, PostcodeFormat::fits($country, $postcode));
    }

    public function testPostcodesAreKeptInCapitalsWithSingleSpaces(): void
    {
        self::assertSame(['SW1A 1AA', '1000'], [PostcodeFormat::normalize(" sw1a \t 1aa "), PostcodeFormat::normalize('1000')]);
        self::assertSame(['1000', null], [PostcodeFormat::example('BG'), PostcodeFormat::example('AE')]);
    }
}
