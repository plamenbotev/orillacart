<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;

final readonly class TaxSettingsView
{
    /**
     * @param list<string> $bases the choices for basis
     */
    public function __construct(
        public string $basis,
        public ?int $defaultGroupId,
        public array $bases,
        public bool $pricesIncludeTax = false,
        public bool $reverseCharge = false,
        public bool $roundsEachLine = false,
        /** @var list<string> */
        public array $originRegions = [],
        public ?string $provider = null,
        public string $whenProviderFails = TaxSettings::SHOP_RATES,
        /** @var list<array{id: string, label: string}> the tax services add-ons offer */
        public array $providers = [],
        public bool $acceptsCertificates = false,
        public string $shownInShop = TaxSettings::AS_ENTERED,
        public string $shownInCart = TaxSettings::AS_ENTERED,
        public string $priceSuffix = '',
        public bool $samePriceEverywhere = true,
        public bool $shippingTaxedAsItems = false,
        public string $euThreshold = TaxSettings::EU_THRESHOLD,
    ) {}

    /**
     * @param list<array{id: string, label: string}> $providers
     */
    public static function fromSettings(TaxSettings $settings, array $providers = []): self
    {
        return new self($settings->basis->value, $settings->defaultGroupId, TaxBasis::values(), $settings->pricesIncludeTax, $settings->reverseCharge, $settings->roundsEachLine, $settings->originRegions, $settings->provider, $settings->whenProviderFails, $providers, $settings->acceptsCertificates, $settings->shownInShop, $settings->shownInCart, $settings->priceSuffix, $settings->samePriceEverywhere, $settings->shippingTaxedAsItems, $settings->euThreshold);
    }
}
