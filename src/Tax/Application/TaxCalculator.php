<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Geography\Application\Dto\LocationView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Tax\Application\Dto\AppliedTaxesView;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Domain\AppliedTaxes;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxLocation;
use OrillaCart\Tax\Domain\TaxSettingsRepository;

/**
 * Which taxes apply to an item: the cart, checkout and orders ask here, never read tax rates themselves.
 */
final readonly class TaxCalculator
{
    public function __construct(
        private TaxGroupRepository $groups,
        private TaxSettingsRepository $settings,
        private SettingsService $shop,
        private GeographyService $geography,
        private TaxExemptions $exemptions,
    ) {}

    /**
     * The customer's exemption that covers a sale to these addresses today, where its tax would be charged; null
     * for a guest or a customer without one that holds there.
     */
    public function exemption(?int $customerId, ?TaxAddress $shipping, ?TaxAddress $billing): ?TaxExemptionView
    {
        if ($customerId === null) {
            return null;
        }
        $shop = $this->shop->current();
        $location = $this->settings->load()->locate($this->locate($shipping), $this->locate($billing), $this->locate(new TaxAddress($shop->shopCountry, $shop->shopState, $shop->shopPostcode)));

        return $location === null ? null : $this->exemptions->covering($customerId, $location);
    }

    public function taxes(TaxQuery $query): AppliedTaxesView
    {
        return AppliedTaxesView::fromTaxes($this->applied($query));
    }

    /** Whether each line's tax is rounded, rather than each rate once over the order; see TaxSettings. */
    public function roundsEachLine(): bool
    {
        return $this->settings->load()->roundsEachLine;
    }

    /** Whether prices are entered including tax; see TaxSettings. */
    public function pricesIncludeTax(): bool
    {
        return $this->settings->load()->pricesIncludeTax;
    }

    /** With prices entered including tax: whether every customer pays the price as entered; see TaxSettings. */
    public function samePriceEverywhere(): bool
    {
        return $this->settings->load()->samePriceEverywhere;
    }

    /** Whether prices show with tax: in the cart and checkout, or in the catalog; see TaxSettings. */
    public function showsTax(bool $inCart): bool
    {
        return $this->settings->load()->showsTax($inCart);
    }

    /** How the catalog shows prices: TaxSettings::AS_ENTERED, WITH_TAX or WITHOUT_TAX. */
    public function shownInShop(): string
    {
        return $this->settings->load()->shownInShop;
    }

    /** The text after the catalog's prices; see TaxSettings. */
    public function priceSuffix(): string
    {
        return $this->settings->load()->priceSuffix;
    }

    /**
     * The taxes of the group at the shop's own address, whoever buys: what a price entered including tax holds
     * when the customer owes none, as for an export.
     */
    public function shopTaxes(?int $groupId): AppliedTaxesView
    {
        $settings = $this->settings->load();
        $groupId ??= $settings->defaultGroupId;
        $group = $groupId === null ? null : $this->groups->find($groupId);
        $shop = $this->shop->current();
        $location = $this->locate(new TaxAddress($shop->shopCountry, $shop->shopState, $shop->shopPostcode));

        return AppliedTaxesView::fromTaxes($group === null || $location === null ? AppliedTaxes::none() : $group->taxesAt($location));
    }

    private function applied(TaxQuery $query): AppliedTaxes
    {
        $settings = $this->settings->load();
        $groupId = $query->groupId ?? $settings->defaultGroupId;
        $group = $groupId === null ? null : $this->groups->find($groupId);
        if ($group === null) {
            return AppliedTaxes::none();
        }

        $shop = $this->shop->current();
        $location = $settings->locate(
            $this->locate($query->shipping),
            $this->locate($query->billing),
            $this->locate(new TaxAddress($shop->shopCountry, $shop->shopState, $shop->shopPostcode)),
        );

        return $location === null ? AppliedTaxes::none() : $group->taxesAt($location);
    }

    private function locate(?TaxAddress $address): ?TaxLocation
    {
        $location = $address === null ? null : $this->geography->locate($address->country, $address->state);

        return $location instanceof LocationView ? new TaxLocation($location->country, $location->state, $location->inEu, $address?->postcode) : null;
    }
}
