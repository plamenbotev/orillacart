<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Domain\CountryDefaults;
use OrillaCart\Setup\Application\Dto\CountryProfileView;
use OrillaCart\Setup\Domain\LegalProfiles;
use OrillaCart\Tax\Domain\Percentage;
use OrillaCart\Tax\Domain\Profile\RegionalTax;
use OrillaCart\Tax\Domain\Profile\TaxProfiles;

/**
 * A country as the setup's questions need it: its money, taxes and law, in one answer.
 */
final readonly class CountryProfiles
{
    public function __construct(private GeographyService $geography) {}

    public function of(string $country): CountryProfileView
    {
        $tax = TaxProfiles::of($country);
        $legal = LegalProfiles::of($country);
        $money = CountryDefaults::money($country);
        $currencies = array_values(array_unique(array_map(static fn(array $row): string => $row[0], CountryDefaults::MONEY)));
        sort($currencies);
        $duty = $legal->eInvoices;

        return new CountryProfileView(
            $country,
            $this->geography->locate($country, null)?->inEu === true,
            $money['currency'] ?? null,
            CountryDefaults::weightUnit($country)->value,
            CountryDefaults::dimensionUnit($country)->value,
            $tax->system->value,
            $tax->name,
            $tax->standard === null ? null : (string) $tax->standard,
            array_map(static fn(Percentage $rate): string => (string) $rate, $tax->reduced),
            $tax->superReduced === null ? null : (string) $tax->superReduced,
            array_map(static fn(RegionalTax $regional): array => ['state' => $regional->state, 'name' => $regional->name, 'percent' => (string) $regional->percent], $tax->regional),
            $tax->pricesIncludeTax,
            $tax->smallBusinessNote,
            $tax->checkedOn,
            $legal->withdrawalDays,
            $legal->legalNotice,
            $legal->legalNoticeTitle,
            $legal->consumerCheckout,
            $duty === null ? null : "{$duty->from}: {$duty->how}",
            $currencies,
        );
    }
}
