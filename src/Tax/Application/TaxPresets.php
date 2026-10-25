<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Translations;
use OrillaCart\Tax\Application\Dto\PresetGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\Dto\TaxGroupView;
use OrillaCart\Tax\Application\Dto\TaxPreset;
use OrillaCart\Tax\Application\Dto\TaxSetup;
use OrillaCart\Tax\Application\Dto\UpdateTaxSettings;
use OrillaCart\Tax\Domain\Percentage;
use OrillaCart\Tax\Domain\Profile\RegionalTax;
use OrillaCart\Tax\Domain\Profile\TaxProfile;
use OrillaCart\Tax\Domain\Profile\TaxProfiles;
use OrillaCart\Tax\Domain\Profile\TaxSystem;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;

/**
 * The tax groups and settings a shop starts with, from its country's profile and what the setup asked:
 * - VAT: a group for each rate the country has (standard, reduced, …, zero), with the shop's own rates, or every
 *   EU country's in the One-Stop Shop; prices with tax for consumers, the reverse charge for EU businesses;
 * - Canada: GST or HST in every province, a province's own tax where the shop is registered for it;
 * - the US: each state's base rate where the shop has nexus, or a tax service;
 * - a small business charges none, and its invoices say why.
 */
final readonly class TaxPresets
{
    public function __construct(
        private TaxGroupService $groups,
        private TaxSettingsService $settings,
        private GeographyService $geography,
        private Translations $translations,
    ) {}

    public function preset(TaxSetup $setup): TaxPreset
    {
        $profile = TaxProfiles::of($setup->shopCountry);
        $consumers = $setup->consumers;
        $vat = \in_array($profile->system, [TaxSystem::Vat, TaxSystem::Gst], true);
        $eu = $this->geography->locate($setup->shopCountry, null)?->inEu === true;
        $including = $consumers && $profile->pricesIncludeTax && $setup->provider === null;
        $warnings = [];

        if (!$setup->registered) {
            $note = $setup->smallBusinessNote ?? $profile->smallBusinessNote;

            return new TaxPreset([], TaxBasis::ShopAddress->value, $including, false, $including ? TaxSettings::WITH_TAX : TaxSettings::WITHOUT_TAX, null, false, null, $profile->name, $note, $note === null ? [Text::of('Invoices will say no tax is charged; add the reason the law of your country asks for in Invoices → Footer.')] : [], $profile->checkedOn);
        }

        $groups = match ($profile->system) {
            TaxSystem::Vat, TaxSystem::Gst => $profile->regional !== [] ? $this->canadian($profile, $setup) : $this->vat($profile, $eu && $setup->oss),
            TaxSystem::SalesTax => $setup->provider !== null ? [new PresetGroup(PresetGroup::STANDARD, [])] : $this->states($profile, $setup->regions),
            TaxSystem::Unknown => $setup->ownRate === null ? [] : [new PresetGroup(PresetGroup::STANDARD, [new SaveTaxRate($setup->shopCountry, null, $setup->ownRate, $profile->name)]), new PresetGroup(PresetGroup::ZERO, [])],
        };
        if ($eu && $setup->oss && \count($groups) > 2) {
            $warnings[] = Text::of('Reduced rates cover different goods from one EU country to the next: check which group each product belongs to.');
        }
        if ($profile->system === TaxSystem::SalesTax && $setup->provider === null) {
            $warnings[] = Text::of('Most states add local sales taxes to their own rate. A tax service charges them, or enter them in Tax by hand.');
        }
        if ($profile->system === TaxSystem::Unknown) {
            $warnings[] = $setup->ownRate === null
                ? Text::of('OrillaCart knows no tax rates for this country, so no tax is charged; enter a rate if the shop must charge one.')
                : Text::of('OrillaCart knows no tax rates for this country: check %s%% with your tax authority.', $setup->ownRate);
        }

        return new TaxPreset(
            $groups,
            ($eu ? ($setup->oss ? TaxBasis::EuDestination : TaxBasis::EuOrigin) : TaxBasis::ShippingAddress)->value,
            $including,
            $eu && $setup->businesses,
            $including ? TaxSettings::WITH_TAX : TaxSettings::WITHOUT_TAX,
            $including && $vat ? Text::of('incl. %s, plus shipping', $profile->name) : null,
            $vat,
            $profile->system === TaxSystem::SalesTax ? $setup->provider : null,
            $profile->name,
            null,
            $warnings,
            $profile->checkedOn,
        );
    }

    /** What the shop calls a group of this kind, in its own language. */
    public function groupName(string $kind): string
    {
        return $this->translations->text(match ($kind) {
            PresetGroup::STANDARD => 'Standard rate',
            PresetGroup::REDUCED => 'Reduced rate',
            PresetGroup::SECOND_REDUCED => 'Second reduced rate',
            PresetGroup::SUPER_REDUCED => 'Super-reduced rate',
            default => 'Zero rate',
        });
    }

    /** The shop's group of this kind, by its name. */
    public function group(string $kind): ?TaxGroupView
    {
        $name = $this->groupName($kind);
        foreach ($this->groups->groups() as $group) {
            if ($group->name === $name) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Makes the group, or gives one the shop has the preset's rates instead of its own.
     *
     * @throws RuleViolation
     */
    public function applyGroup(PresetGroup $preset): TaxGroupView
    {
        $group = $this->group($preset->kind) ?? $this->groups->create(new SaveTaxGroup($this->groupName($preset->kind)));
        foreach ($group->rates as $rate) {
            $this->groups->removeRate($group->id, $rate->id);
        }
        foreach ($preset->rates as $rate) {
            $this->groups->addRate($group->id, $rate);
        }

        return $this->groups->group($group->id);
    }

    /**
     * Sets how the shop taxes, the standard group as the default; the rest as it is.
     *
     * @throws RuleViolation
     */
    public function applySettings(TaxPreset $preset): void
    {
        $current = $this->settings->current();
        $this->settings->update(new UpdateTaxSettings(
            $preset->basis,
            $preset->groups === [] ? null : $this->group(PresetGroup::STANDARD)?->id,
            $preset->pricesIncludeTax,
            $preset->reverseCharge,
            $current->roundsEachLine,
            $current->originRegions,
            $preset->provider,
            $current->whenProviderFails,
            $current->acceptsCertificates,
            $preset->shown,
            $preset->shown,
            $preset->priceSuffix === null ? '' : $this->translations->text($preset->priceSuffix),
            $current->samePriceEverywhere,
            $preset->shippingTaxedAsItems,
        ));
    }

    /** Whether the shop taxes as the preset says already. */
    public function settled(TaxPreset $preset): bool
    {
        $current = $this->settings->current();

        return $current->basis === $preset->basis
            && $current->defaultGroupId === ($preset->groups === [] ? null : $this->group(PresetGroup::STANDARD)?->id)
            && $current->pricesIncludeTax === $preset->pricesIncludeTax
            && $current->reverseCharge === $preset->reverseCharge
            && $current->shownInShop === $preset->shown
            && $current->shownInCart === $preset->shown
            && $current->priceSuffix === ($preset->priceSuffix === null ? '' : $this->translations->text($preset->priceSuffix))
            && $current->shippingTaxedAsItems === $preset->shippingTaxedAsItems
            && $current->provider === $preset->provider;
    }

    /**
     * A group per rate: the shop's own country, or each EU country's matching rate (the next higher where a
     * country has fewer), and a zero rate.
     *
     * @return list<PresetGroup>
     */
    private function vat(TaxProfile $profile, bool $everyEuCountry): array
    {
        $countries = [$profile];
        if ($everyEuCountry) {
            $countries = [];
            foreach (TaxProfiles::countries() as $country) {
                if ($this->geography->locate($country, null)?->inEu === true) {
                    $countries[] = TaxProfiles::of($country);
                }
            }
        }
        $kinds = [PresetGroup::STANDARD => null];
        foreach (\array_slice($profile->reduced, 0, 2) as $position => $_) {
            $kinds[$position === 0 ? PresetGroup::REDUCED : PresetGroup::SECOND_REDUCED] = $position;
        }
        if ($profile->superReduced !== null) {
            $kinds[PresetGroup::SUPER_REDUCED] = -1;
        }

        $groups = [];
        foreach ($kinds as $kind => $position) {
            $rates = [];
            foreach ($countries as $country) {
                $percent = self::rateOf($country, $position);
                if ($percent !== null) {
                    $rates[] = new SaveTaxRate($country->country, null, (string) $percent, $profile->name);
                }
            }
            $groups[] = new PresetGroup($kind, $rates);
        }
        $groups[] = new PresetGroup(PresetGroup::ZERO, []);

        return $groups;
    }

    /**
     * The country's rate for a kind: its standard (null position), its nth reduced, or its super-reduced (-1),
     * falling back to the one above when it has none.
     */
    private static function rateOf(TaxProfile $country, ?int $position): ?Percentage
    {
        if ($position === null) {
            return $country->standard;
        }
        if ($position === -1) {
            return $country->superReduced ?? ($country->reduced === [] ? $country->standard : $country->reduced[\count($country->reduced) - 1]);
        }

        return $country->reduced[$position] ?? ($country->reduced === [] ? $country->standard : $country->reduced[\count($country->reduced) - 1]);
    }

    /**
     * GST or HST in every province and territory, and a province's own tax (PST, QST, RST) where the shop is
     * registered for it.
     *
     * @return list<PresetGroup>
     */
    private function canadian(TaxProfile $profile, TaxSetup $setup): array
    {
        $rates = [];
        foreach ($profile->regional as $tax) {
            if (\in_array($tax->name, ['GST', 'HST'], true) || \in_array($tax->state, $setup->regions, true)) {
                $rates[] = self::regional($profile->country, $tax);
            }
        }

        return [new PresetGroup(PresetGroup::STANDARD, $rates), new PresetGroup(PresetGroup::ZERO, [])];
    }

    /**
     * Each state's own rate where the shop has nexus.
     *
     * @param list<string> $regions
     *
     * @return list<PresetGroup>
     */
    private function states(TaxProfile $profile, array $regions): array
    {
        $rates = [];
        foreach ($regions as $state) {
            foreach ($profile->taxesOf($state) as $tax) {
                $rates[] = self::regional($profile->country, $tax);
            }
        }

        return [new PresetGroup(PresetGroup::STANDARD, $rates), new PresetGroup(PresetGroup::ZERO, [])];
    }

    private static function regional(string $country, RegionalTax $tax): SaveTaxRate
    {
        return new SaveTaxRate($country, $tax->state, (string) $tax->percent, $tax->name);
    }
}
