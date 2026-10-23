<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: replace the tax settings.
 */
final readonly class UpdateTaxSettings
{
    public function __construct(
        #[Assert\Choice(callback: [TaxBasis::class, 'values'])]
        public string $basis,
        #[Assert\Positive]
        public ?int $defaultGroupId,
        public bool $pricesIncludeTax = false,
        public bool $reverseCharge = false,
        /** Each line's tax rounded, instead of each rate once over the order. */
        public bool $roundsEachLine = false,
        /** @var list<string> regions taxing sales within them at the seller's address: "US-TX" */
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex(TaxSettings::REGION, message: 'Use a country and a state code, such as US-TX.')])]
        public array $originRegions = [],
        /** A tax service's id; null for the shop's own rates. */
        #[Assert\Regex('/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $provider = null,
        #[Assert\Choice(choices: [TaxSettings::SHOP_RATES, TaxSettings::REFUSE])]
        public string $whenProviderFails = TaxSettings::SHOP_RATES,
        /** Customers send their exemption certificates for the shop to check. */
        public bool $acceptsCertificates = false,
    ) {}
}
