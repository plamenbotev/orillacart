<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * Business tax numbers outside the EU, checked by their format and check digit without asking anyone: the UK's VAT
 * number, Switzerland's UID, Norway's MVA number, Australia's ABN, New Zealand's GST (IRD) number and Canada's
 * business number. EU VAT numbers are VIES's to confirm (VatNumbers).
 */
final class TaxNumber
{
    /** The countries whose numbers are checked here. */
    public const array COUNTRIES = ['GB', 'CH', 'NO', 'AU', 'NZ', 'CA'];

    /**
     * The number written the usual way ("GB980780684", "CHE116281710", "NO974760673MVA", "51824753556", "49091850",
     * "123456782RT0001"), or null when it is not one of the country's; a country without a check here keeps what
     * was typed, in capitals without spaces.
     */
    public static function check(string $country, string $entered): ?string
    {
        $typed = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $entered));
        if ($typed === '') {
            return null;
        }

        return match ($country) {
            'GB' => self::uk($typed),
            'CH' => self::swiss($typed),
            'NO' => self::norwegian($typed),
            'AU' => preg_match('/^\d{11}$/', $typed) === 1 && self::abn($typed) ? $typed : null,
            'NZ' => self::newZealand($typed),
            'CA' => self::canadian($typed),
            default => preg_match('/^[0-9A-Z]{2,20}$/', $typed) === 1 ? $typed : null,
        };
    }

    /** What the country calls the number, for the checkout and the invoice. */
    public static function label(string $country): string
    {
        return match ($country) {
            'GB' => 'VAT number',
            'CH' => 'UID / VAT number',
            'NO' => 'Organisation number (MVA)',
            'AU' => 'ABN',
            'NZ' => 'GST number',
            'CA' => 'Business number',
            'US' => 'Tax ID',
            default => 'VAT number',
        };
    }

    private static function uk(string $typed): ?string
    {
        $number = str_starts_with($typed, 'GB') ? substr($typed, 2) : $typed;
        // Government departments and health authorities: GD or HA and three digits.
        if (preg_match('/^(GD[0-4]\d\d|HA[5-9]\d\d)$/', $number) === 1) {
            return 'GB' . $number;
        }
        if (preg_match('/^\d{9}(\d{3})?$/', $number) !== 1) {
            return null;
        }
        $sum = self::weighted(substr($number, 0, 7), [8, 7, 6, 5, 4, 3, 2]) + (int) substr($number, 7, 2);

        return $sum % 97 === 0 || ($sum + 55) % 97 === 0 ? 'GB' . $number : null;
    }

    private static function swiss(string $typed): ?string
    {
        // CHE-116.281.710 MWST (or TVA, IVA): the UID, and the VAT register it is in.
        if (preg_match('/^CHE(\d{9})(MWST|TVA|IVA)?$/', $typed, $parts) !== 1) {
            return null;
        }

        return self::mod11($parts[1], [5, 4, 3, 2, 7, 6, 5, 4]) ? 'CHE' . $parts[1] . ($parts[2] ?? '') : null;
    }

    private static function norwegian(string $typed): ?string
    {
        if (preg_match('/^(?:NO)?(\d{9})(?:MVA)?$/', $typed, $parts) !== 1) {
            return null;
        }

        return self::mod11($parts[1], [3, 2, 7, 6, 5, 4, 3, 2]) ? 'NO' . $parts[1] . 'MVA' : null;
    }

    private static function abn(string $number): bool
    {
        $digits = array_map(intval(...), str_split($number));
        --$digits[0];
        $sum = 0;
        foreach ([10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19] as $index => $weight) {
            $sum += $digits[$index] * $weight;
        }

        return $sum % 89 === 0;
    }

    private static function newZealand(string $typed): ?string
    {
        if (preg_match('/^\d{8,9}$/', $typed) !== 1 || (int) $typed < 10_000_000 || (int) $typed > 150_000_000) {
            return null;
        }
        $number = str_pad($typed, 9, '0', \STR_PAD_LEFT);
        foreach ([[3, 2, 7, 6, 5, 4, 3, 2], [7, 4, 3, 2, 5, 2, 7, 6]] as $weights) {
            $remainder = self::weighted(substr($number, 0, 8), $weights) % 11;
            $check = $remainder === 0 ? 0 : 11 - $remainder;
            if ($check !== 10) {
                return $check === (int) $number[8] ? $typed : null;
            }
        }

        return null;
    }

    private static function canadian(string $typed): ?string
    {
        // 123456782, or with its program account: 123456782RT0001.
        if (preg_match('/^(\d{9})([A-Z]{2}\d{4})?$/', $typed, $parts) !== 1) {
            return null;
        }
        $sum = 0;
        foreach (array_reverse(str_split($parts[1])) as $index => $digit) {
            $value = (int) $digit * ($index % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0 ? $typed : null;
    }

    /**
     * @param list<int> $weights for the first eight digits; the ninth checks them
     */
    private static function mod11(string $number, array $weights): bool
    {
        $check = 11 - self::weighted(substr($number, 0, 8), $weights) % 11;
        $check = $check === 11 ? 0 : $check;

        return $check !== 10 && $check === (int) $number[8];
    }

    /**
     * @param list<int> $weights
     */
    private static function weighted(string $digits, array $weights): int
    {
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }

        return $sum;
    }
}
