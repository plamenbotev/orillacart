<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain\Profile;

use OrillaCart\Tax\Domain\Percentage;

/**
 * What each country the shop knows charges, as in force on 2026-10-25. Checked against the European
 * Commission's VAT rates table and the national tax authorities (Canada's CRA; US state base rates from the Tax
 * Foundation's mid-2026 table), and edited after. Small-business notes are the wording the law prescribes (FR, LU,
 * PT) or the usual one where it asks only for a reference. Every other country is unknown: the owner enters its
 * rate.
 */
final class TaxProfiles
{
    public const string CHECKED_ON = '2026-10-25';

    public static function of(string $country): TaxProfile
    {
        return match ($country) {
            'AT' => new TaxProfile('AT', TaxSystem::Vat, 'USt.', standard: Percentage::of('20'), reduced: [Percentage::of('13'), Percentage::of('10')], superReduced: Percentage::of('4.9'), smallBusinessNote: 'Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung gem. § 6 Abs. 1 Z 27 UStG.', checkedOn: self::CHECKED_ON),
            'AU' => new TaxProfile('AU', TaxSystem::Gst, 'GST', standard: Percentage::of('10'), checkedOn: self::CHECKED_ON),
            'BE' => new TaxProfile('BE', TaxSystem::Vat, 'BTW/TVA', standard: Percentage::of('21'), reduced: [Percentage::of('12'), Percentage::of('6')], smallBusinessNote: 'Régime particulier de franchise des petites entreprises.', checkedOn: self::CHECKED_ON),
            'BG' => new TaxProfile('BG', TaxSystem::Vat, 'ДДС (DDS)', standard: Percentage::of('20'), reduced: [Percentage::of('9')], checkedOn: self::CHECKED_ON),
            'CH' => new TaxProfile('CH', TaxSystem::Vat, 'MWST', standard: Percentage::of('8.1'), reduced: [Percentage::of('2.6')], checkedOn: self::CHECKED_ON),
            'CY' => new TaxProfile('CY', TaxSystem::Vat, 'ΦΠΑ (FPA)', standard: Percentage::of('19'), reduced: [Percentage::of('9'), Percentage::of('5')], superReduced: Percentage::of('3'), checkedOn: self::CHECKED_ON),
            'CZ' => new TaxProfile('CZ', TaxSystem::Vat, 'DPH', standard: Percentage::of('21'), reduced: [Percentage::of('12')], checkedOn: self::CHECKED_ON),
            'DE' => new TaxProfile('DE', TaxSystem::Vat, 'MwSt.', standard: Percentage::of('19'), reduced: [Percentage::of('7')], smallBusinessNote: 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.', checkedOn: self::CHECKED_ON),
            'DK' => new TaxProfile('DK', TaxSystem::Vat, 'moms', standard: Percentage::of('25'), checkedOn: self::CHECKED_ON),
            'EE' => new TaxProfile('EE', TaxSystem::Vat, 'km', standard: Percentage::of('24'), reduced: [Percentage::of('13'), Percentage::of('9')], checkedOn: self::CHECKED_ON),
            'ES' => new TaxProfile('ES', TaxSystem::Vat, 'IVA', standard: Percentage::of('21'), reduced: [Percentage::of('10')], superReduced: Percentage::of('4'), checkedOn: self::CHECKED_ON),
            'FI' => new TaxProfile('FI', TaxSystem::Vat, 'ALV', standard: Percentage::of('25.5'), reduced: [Percentage::of('13.5'), Percentage::of('10')], checkedOn: self::CHECKED_ON),
            'FR' => new TaxProfile('FR', TaxSystem::Vat, 'TVA', standard: Percentage::of('20'), reduced: [Percentage::of('10'), Percentage::of('5.5')], superReduced: Percentage::of('2.1'), smallBusinessNote: 'TVA non applicable, art. 293 B du CGI.', checkedOn: self::CHECKED_ON),
            'GB' => new TaxProfile('GB', TaxSystem::Vat, 'VAT', standard: Percentage::of('20'), reduced: [Percentage::of('5')], checkedOn: self::CHECKED_ON),
            'GR' => new TaxProfile('GR', TaxSystem::Vat, 'ΦΠΑ (FPA)', standard: Percentage::of('24'), reduced: [Percentage::of('13'), Percentage::of('6')], smallBusinessNote: 'Απαλλαγή από ΦΠΑ βάσει του άρθρου 39 του Κώδικα ΦΠΑ.', checkedOn: self::CHECKED_ON),
            'HR' => new TaxProfile('HR', TaxSystem::Vat, 'PDV', standard: Percentage::of('25'), reduced: [Percentage::of('13'), Percentage::of('5')], smallBusinessNote: 'Oslobođeno plaćanja PDV-a prema čl. 90. st. 1. Zakona o porezu na dodanu vrijednost.', checkedOn: self::CHECKED_ON),
            'HU' => new TaxProfile('HU', TaxSystem::Vat, 'ÁFA', standard: Percentage::of('27'), reduced: [Percentage::of('18'), Percentage::of('5')], smallBusinessNote: 'Alanyi adómentes.', checkedOn: self::CHECKED_ON),
            'IE' => new TaxProfile('IE', TaxSystem::Vat, 'VAT', standard: Percentage::of('23'), reduced: [Percentage::of('13.5'), Percentage::of('9')], checkedOn: self::CHECKED_ON),
            'IT' => new TaxProfile('IT', TaxSystem::Vat, 'IVA', standard: Percentage::of('22'), reduced: [Percentage::of('10'), Percentage::of('5')], superReduced: Percentage::of('4'), smallBusinessNote: 'Operazione effettuata ai sensi dell\'articolo 1, commi da 54 a 89, della Legge n. 190/2014.', checkedOn: self::CHECKED_ON),
            'LT' => new TaxProfile('LT', TaxSystem::Vat, 'PVM', standard: Percentage::of('21'), reduced: [Percentage::of('12'), Percentage::of('5')], checkedOn: self::CHECKED_ON),
            'LU' => new TaxProfile('LU', TaxSystem::Vat, 'TVA', standard: Percentage::of('17'), reduced: [Percentage::of('14'), Percentage::of('8')], superReduced: Percentage::of('3'), smallBusinessNote: 'TVA non applicable – Article 57bis de la loi modifiée du 12 février 1979.', checkedOn: self::CHECKED_ON),
            'LV' => new TaxProfile('LV', TaxSystem::Vat, 'PVN', standard: Percentage::of('21'), reduced: [Percentage::of('12'), Percentage::of('5')], checkedOn: self::CHECKED_ON),
            'MT' => new TaxProfile('MT', TaxSystem::Vat, 'VAT', standard: Percentage::of('18'), reduced: [Percentage::of('12'), Percentage::of('7'), Percentage::of('5')], checkedOn: self::CHECKED_ON),
            'NL' => new TaxProfile('NL', TaxSystem::Vat, 'btw', standard: Percentage::of('21'), reduced: [Percentage::of('9')], smallBusinessNote: 'Vrijgesteld van btw op grond van de kleineondernemersregeling (KOR).', checkedOn: self::CHECKED_ON),
            'NO' => new TaxProfile('NO', TaxSystem::Vat, 'MVA', standard: Percentage::of('25'), reduced: [Percentage::of('15'), Percentage::of('12')], checkedOn: self::CHECKED_ON),
            'NZ' => new TaxProfile('NZ', TaxSystem::Gst, 'GST', standard: Percentage::of('15'), checkedOn: self::CHECKED_ON),
            'PL' => new TaxProfile('PL', TaxSystem::Vat, 'VAT', standard: Percentage::of('23'), reduced: [Percentage::of('8'), Percentage::of('5')], smallBusinessNote: 'Zwolnienie z VAT na podstawie art. 113 ust. 1 ustawy o podatku od towarów i usług.', checkedOn: self::CHECKED_ON),
            'PT' => new TaxProfile('PT', TaxSystem::Vat, 'IVA', standard: Percentage::of('23'), reduced: [Percentage::of('13'), Percentage::of('6')], smallBusinessNote: 'IVA - regime de isenção.', checkedOn: self::CHECKED_ON),
            'RO' => new TaxProfile('RO', TaxSystem::Vat, 'TVA', standard: Percentage::of('21'), reduced: [Percentage::of('11')], smallBusinessNote: 'Scutit de TVA conform art. 310 din Codul fiscal.', checkedOn: self::CHECKED_ON),
            'SE' => new TaxProfile('SE', TaxSystem::Vat, 'moms', standard: Percentage::of('25'), reduced: [Percentage::of('12'), Percentage::of('6')], smallBusinessNote: 'Undantagen från momsplikt enligt 18 kap. 4 § mervärdesskattelagen (2023:200).', checkedOn: self::CHECKED_ON),
            'SI' => new TaxProfile('SI', TaxSystem::Vat, 'DDV', standard: Percentage::of('22'), reduced: [Percentage::of('9.5'), Percentage::of('5')], smallBusinessNote: 'DDV ni obračunan na podlagi 1. odstavka 94. člena ZDDV-1.', checkedOn: self::CHECKED_ON),
            'SK' => new TaxProfile('SK', TaxSystem::Vat, 'DPH', standard: Percentage::of('23'), reduced: [Percentage::of('19'), Percentage::of('5')], checkedOn: self::CHECKED_ON),
            'CA' => new TaxProfile('CA', TaxSystem::Gst, 'GST', regional: self::regional([
                ['AB', 'GST', '5'],
                ['BC', 'GST', '5'],
                ['BC', 'PST', '7'],
                ['MB', 'GST', '5'],
                ['MB', 'RST', '7'],
                ['NB', 'HST', '15'],
                ['NL', 'HST', '15'],
                ['NS', 'HST', '14'],
                ['NT', 'GST', '5'],
                ['NU', 'GST', '5'],
                ['ON', 'HST', '13'],
                ['PE', 'HST', '15'],
                ['QC', 'GST', '5'],
                ['QC', 'QST', '9.975'],
                ['SK', 'GST', '5'],
                ['SK', 'PST', '6'],
                ['YT', 'GST', '5'],
            ]), pricesIncludeTax: false, checkedOn: self::CHECKED_ON),
            'US' => new TaxProfile('US', TaxSystem::SalesTax, 'Sales tax', regional: self::regional([
                ['AL', 'Sales tax', '4'],
                ['AR', 'Sales tax', '6.5'],
                ['AZ', 'Sales tax', '5.6'],
                ['CA', 'Sales tax', '7.25'],
                ['CO', 'Sales tax', '2.9'],
                ['CT', 'Sales tax', '6.35'],
                ['DC', 'Sales tax', '6'],
                ['FL', 'Sales tax', '6'],
                ['GA', 'Sales tax', '4'],
                ['HI', 'Sales tax', '4'],
                ['IA', 'Sales tax', '6'],
                ['ID', 'Sales tax', '6'],
                ['IL', 'Sales tax', '6.25'],
                ['IN', 'Sales tax', '7'],
                ['KS', 'Sales tax', '6.5'],
                ['KY', 'Sales tax', '6'],
                ['LA', 'Sales tax', '5'],
                ['MA', 'Sales tax', '6.25'],
                ['MD', 'Sales tax', '6'],
                ['ME', 'Sales tax', '5.5'],
                ['MI', 'Sales tax', '6'],
                ['MN', 'Sales tax', '6.875'],
                ['MO', 'Sales tax', '4.225'],
                ['MS', 'Sales tax', '7'],
                ['NC', 'Sales tax', '4.75'],
                ['ND', 'Sales tax', '5'],
                ['NE', 'Sales tax', '5.5'],
                ['NJ', 'Sales tax', '6.625'],
                ['NM', 'Sales tax', '4.875'],
                ['NV', 'Sales tax', '6.85'],
                ['NY', 'Sales tax', '4'],
                ['OH', 'Sales tax', '5.75'],
                ['OK', 'Sales tax', '4.5'],
                ['PA', 'Sales tax', '6'],
                ['RI', 'Sales tax', '7'],
                ['SC', 'Sales tax', '6'],
                ['SD', 'Sales tax', '4.2'],
                ['TN', 'Sales tax', '7'],
                ['TX', 'Sales tax', '6.25'],
                ['UT', 'Sales tax', '6.1'],
                ['VA', 'Sales tax', '5.3'],
                ['VT', 'Sales tax', '6'],
                ['WA', 'Sales tax', '6.5'],
                ['WI', 'Sales tax', '5'],
                ['WV', 'Sales tax', '6'],
                ['WY', 'Sales tax', '4'],
            ]), pricesIncludeTax: false, checkedOn: self::CHECKED_ON),
            default => new TaxProfile($country, TaxSystem::Unknown, 'Tax'),
        };
    }

    /** @return list<string> the countries with a profile */
    public static function countries(): array
    {
        return ['AT', 'AU', 'BE', 'BG', 'CA', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'NO', 'NZ', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'US'];
    }

    /**
     * @param list<array{string, string, string}> $taxes [state, name, percent]
     *
     * @return list<RegionalTax>
     */
    private static function regional(array $taxes): array
    {
        return array_map(static fn(array $tax): RegionalTax => new RegionalTax($tax[0], $tax[1], Percentage::of($tax[2])), $taxes);
    }
}
