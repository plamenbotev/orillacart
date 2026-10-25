<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * The tax groups and settings the setup would make, from the country's profile and the owner's answers.
 */
final readonly class TaxPreset
{
    /**
     * @param list<PresetGroup> $groups
     * @param list<Text>        $warnings what the owner should know: at most one
     */
    public function __construct(
        public array $groups,
        public string $basis,
        public bool $pricesIncludeTax,
        public bool $reverseCharge,
        public string $shown,
        /** After prices in the shop: "incl. VAT, plus shipping"; null for none. */
        public ?Text $priceSuffix,
        public bool $shippingTaxedAsItems,
        public ?string $provider,
        /** The tax as the shop's documents name it: VAT, MwSt., GST. */
        public string $taxName,
        /** What invoices say when the shop charges no tax as a small business; null when it charges tax. */
        public ?string $smallBusinessNote,
        public array $warnings = [],
        public ?string $checkedOn = null,
    ) {}
}
