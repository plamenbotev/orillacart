<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * The standard VAT rate of each EU member state, as known in October 2026, to start a tax group charging EU
 * customers their own rate (OSS). Rates change: the shop checks them against the EU's list
 * (https://taxation-customs.ec.europa.eu/taxation/vat/vat-directive/vat-rates_en) and edits them after.
 */
final class EuStandardRates
{
    /** @var array<string, string> by ISO country code */
    public const array RATES = [
        'AT' => '20', 'BE' => '21', 'BG' => '20', 'CY' => '19', 'CZ' => '21', 'DE' => '19', 'DK' => '25',
        'EE' => '24', 'ES' => '21', 'FI' => '25.5', 'FR' => '20', 'GR' => '24', 'HR' => '25', 'HU' => '27',
        'IE' => '23', 'IT' => '22', 'LT' => '21', 'LU' => '17', 'LV' => '21', 'MT' => '18', 'NL' => '21',
        'PL' => '23', 'PT' => '23', 'RO' => '21', 'SE' => '25', 'SI' => '22', 'SK' => '23',
    ];
}
