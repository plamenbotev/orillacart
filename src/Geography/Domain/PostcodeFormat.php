<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

/**
 * What a country's postcodes look like, for the shop to refuse one mistyped before an order ships to it. Countries
 * not listed take any postcode. Postcodes are compared in capitals, with single spaces.
 */
final class PostcodeFormat
{
    /** @var array<string, array{string, string}> the pattern and an example, by country */
    private const array FORMATS = [
        'AL' => ['\d{4}', '1001'],
        'AR' => ['[A-Z]?\d{4}([A-Z]{3})?', 'C1425'],
        'AT' => ['\d{4}', '1010'],
        'AU' => ['\d{4}', '2000'],
        'BA' => ['\d{5}', '71000'],
        'BE' => ['\d{4}', '1000'],
        'BG' => ['\d{4}', '1000'],
        'BR' => ['\d{5}-?\d{3}', '01310-100'],
        'CA' => ['[ABCEGHJ-NPRSTVXY]\d[A-Z] ?\d[A-Z]\d', 'K1A 0B1'],
        'CH' => ['\d{4}', '8001'],
        'CL' => ['\d{7}', '8320000'],
        'CN' => ['\d{6}', '100000'],
        'CO' => ['\d{6}', '110111'],
        'CY' => ['\d{4}', '1010'],
        'CZ' => ['\d{3} ?\d{2}', '110 00'],
        'DE' => ['\d{5}', '10115'],
        'DK' => ['\d{4}', '1050'],
        'EE' => ['\d{5}', '10111'],
        'ES' => ['\d{5}', '28001'],
        'FI' => ['\d{5}', '00100'],
        'FR' => ['\d{5}', '75001'],
        'GB' => ['(GIR ?0AA|[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2})', 'SW1A 1AA'],
        'GR' => ['\d{3} ?\d{2}', '105 57'],
        'HR' => ['\d{5}', '10000'],
        'HU' => ['\d{4}', '1011'],
        'IE' => ['[AC-FHKNPRTV-Y]\d[\dW] ?[0-9AC-FHKNPRTV-Y]{4}', 'D02 X285'],
        'IL' => ['\d{5}(\d{2})?', '9614303'],
        'IN' => ['\d{6}', '110001'],
        'IS' => ['\d{3}', '101'],
        'IT' => ['\d{5}', '00184'],
        'JP' => ['\d{3}-?\d{4}', '100-0001'],
        'KR' => ['\d{5}', '03187'],
        'LT' => ['(LT-)?\d{5}', 'LT-01100'],
        'LU' => ['(L-)?\d{4}', 'L-1009'],
        'LV' => ['(LV-)?\d{4}', 'LV-1050'],
        'MD' => ['(MD-?)?\d{4}', 'MD-2001'],
        'ME' => ['\d{5}', '81000'],
        'MK' => ['\d{4}', '1000'],
        'MT' => ['[A-Z]{3} ?\d{2,4}', 'VLT 1117'],
        'MX' => ['\d{5}', '06000'],
        'NL' => ['\d{4} ?[A-Z]{2}', '1012 AB'],
        'NO' => ['\d{4}', '0150'],
        'NZ' => ['\d{4}', '6011'],
        'PL' => ['\d{2}-\d{3}', '00-950'],
        'PT' => ['\d{4}-\d{3}', '1000-001'],
        'RO' => ['\d{6}', '010011'],
        'RS' => ['\d{5}', '11000'],
        'RU' => ['\d{6}', '101000'],
        'SE' => ['\d{3} ?\d{2}', '111 22'],
        'SG' => ['\d{6}', '018956'],
        'SI' => ['\d{4}', '1000'],
        'SK' => ['\d{3} ?\d{2}', '811 01'],
        'TR' => ['\d{5}', '06100'],
        'UA' => ['\d{5}', '01001'],
        'US' => ['\d{5}(-\d{4})?', '10001'],
        'ZA' => ['\d{4}', '0001'],
    ];

    /** In capitals with single spaces, as the shop keeps it: "sw1a  1aa" is "SW1A 1AA". */
    public static function normalize(string $postcode): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $postcode)));
    }

    /** Whether the country's postcodes look like this one; any postcode of a country not listed does. */
    public static function fits(string $country, string $postcode): bool
    {
        $format = self::FORMATS[$country] ?? null;

        return $format === null || preg_match('/^' . $format[0] . '$/', self::normalize($postcode)) === 1;
    }

    /** One of the country's postcodes, to show how they look; null for a country not listed. */
    public static function example(string $country): ?string
    {
        return self::FORMATS[$country][1] ?? null;
    }
}
