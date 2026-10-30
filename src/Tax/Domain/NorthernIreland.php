<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * Northern Ireland, in the United Kingdom, follows the EU's VAT rules for goods (the Windsor Framework): goods
 * sent there are taxed as in the EU, by its own VAT (One-Stop-Shop), and a business there has an XI number that
 * VIES checks. Its postcodes start with BT. For services it is the United Kingdom.
 */
final class NorthernIreland
{
    /** VIES's country code for Northern Ireland's businesses. */
    public const string VIES_PREFIX = 'XI';

    /** Whether an address in this country with this postcode is in Northern Ireland. */
    public static function covers(?string $country, ?string $postcode): bool
    {
        return $country === 'GB' && $postcode !== null && preg_match('/^\s*BT\s*\d/i', $postcode) === 1;
    }
}
