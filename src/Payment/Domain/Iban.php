<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

/**
 * An international bank account number, checked by its two check digits (ISO 13616, mod 97).
 */
final class Iban
{
    /** The countries whose banks number accounts by IBAN, as SWIFT's IBAN registry lists them. */
    public const array COUNTRIES = [
        'AD', 'AE', 'AL', 'AT', 'AZ', 'BA', 'BE', 'BG', 'BH', 'BI', 'BR', 'BY', 'CH', 'CR', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DO', 'EE', 'EG',
        'ES', 'FI', 'FK', 'FO', 'FR', 'GB', 'GE', 'GI', 'GL', 'GR', 'GT', 'HR', 'HU', 'IE', 'IL', 'IQ', 'IS', 'IT', 'JO', 'KW', 'KZ', 'LB',
        'LC', 'LI', 'LT', 'LU', 'LV', 'LY', 'MC', 'MD', 'ME', 'MK', 'MN', 'MR', 'MT', 'MU', 'NI', 'NL', 'NO', 'OM', 'PK', 'PL', 'PS', 'PT',
        'QA', 'RO', 'RS', 'RU', 'SA', 'SC', 'SD', 'SE', 'SI', 'SK', 'SM', 'SO', 'ST', 'SV', 'TL', 'TN', 'TR', 'UA', 'VA', 'VG', 'YE',
    ];

    /**
     * The number in capitals without spaces, or null when it is not an IBAN.
     */
    public static function check(string $entered): ?string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $entered));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return null;
        }
        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $character) {
            $digits .= ctype_digit($character) ? $character : (string) (\ord($character) - 55);
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $part) {
            $remainder = (int) ($remainder . $part) % 97;
        }

        return $remainder === 1 ? $iban : null;
    }

    /** Whether it is written as an IBAN is: two letters, two check digits. Elsewhere accounts have other numbers. */
    public static function looksLikeOne(string $entered): bool
    {
        return preg_match('/^[A-Z]{2}\d{2}/', strtoupper((string) preg_replace('/\s+/', '', $entered))) === 1;
    }

    /** Written in groups of four, as people read it. */
    public static function grouped(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }
}
