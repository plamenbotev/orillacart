<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Domain;

/**
 * The legal profiles the setup knows, as checked on 2026-10-25:
 * - the EU's 14 days to withdraw (Directive 2011/83/EU) and the seller's details on the website (Directive
 *   2000/31/EC art. 5), called an Impressum in Germany (§ 5 DDG) and Austria (§ 5 ECG);
 * - the UK's 14 days (Consumer Contracts Regulations 2013) and Norway's (angrerettloven);
 * - the countries whose B2B invoices must be electronic, and from when. Those run through a national system
 *   (Italy's SdI, Poland's KSeF, …) are not met by the shop's own invoices.
 *
 * Every other country: no duty the setup sets up.
 */
final class LegalProfiles
{
    private const array EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public static function of(string $country): LegalProfile
    {
        $consumer = \in_array($country, [...self::EU, 'GB', 'NO'], true);

        return new LegalProfile(
            $country,
            withdrawalDays: $consumer ? 14 : null,
            legalNotice: $consumer || $country === 'CH',
            legalNoticeTitle: \in_array($country, ['DE', 'AT'], true) ? 'Impressum' : 'Legal notice',
            consumerCheckout: $consumer,
            eInvoices: self::eInvoices($country),
        );
    }

    private static function eInvoices(string $country): ?EInvoiceDuty
    {
        return match ($country) {
            'BE' => new EInvoiceDuty('2026-01-01', 'Peppol, in EN 16931 UBL', true),
            'DE' => new EInvoiceDuty('2028-01-01', 'EN 16931: XRechnung or ZUGFeRD; receiving since 2025', true),
            'FR' => new EInvoiceDuty('2027-09-01', 'through an approved platform (plateforme agréée); receiving since 2026-09-01', false),
            'GR' => new EInvoiceDuty('2026-11-02', 'through myDATA, by a certified provider', false),
            'HR' => new EInvoiceDuty('2026-01-01', 'Fiskalizacija 2.0, reported to the tax authority', false),
            'HU' => new EInvoiceDuty('2021-01-04', 'every invoice reported at once to NAV Online Számla', false),
            'IT' => new EInvoiceDuty('2019-01-01', 'FatturaPA through SdI, to consumers too', false),
            'NO' => new EInvoiceDuty('2027-01-01', 'Peppol, in EHF (Peppol BIS 3.0)', true),
            'PL' => new EInvoiceDuty('2026-04-01', 'through KSeF, in FA(3)', false),
            'PT' => new EInvoiceDuty('2023-01-01', 'certified invoicing software, with ATCUD and a QR code', false),
            'RO' => new EInvoiceDuty('2024-01-01', 'RO e-Factura, through SPV', false),
            'ES' => new EInvoiceDuty('2028-10-06', 'through AEAT’s solution or a private platform; larger businesses from 2027-10-06', false),
            'SK' => new EInvoiceDuty('2027-01-01', 'Peppol, in EN 16931, through an accredited provider', true),
            'LV' => new EInvoiceDuty('2028-01-01', 'structured e-invoices, reported to VID', false),
            'SI' => new EInvoiceDuty('2028-01-01', 'e-SLOG or EN 16931', true),
            default => null,
        };
    }
}
