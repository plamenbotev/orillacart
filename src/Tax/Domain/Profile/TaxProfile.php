<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain\Profile;

use OrillaCart\Tax\Domain\Percentage;

/**
 * What a country charges, to start a shop's tax groups: its standard rate, its reduced ones, or its taxes by
 * state, and whether prices to consumers are given with the tax in them. Rates change: each profile says when
 * it was last checked against the country's own list.
 */
final readonly class TaxProfile
{
    /**
     * @param list<Percentage>  $reduced  the reduced rates, highest first
     * @param list<RegionalTax> $regional taxes by state, where the country has no rate of its own (Canada, the US)
     */
    public function __construct(
        public string $country,
        public TaxSystem $system,
        /** The tax as the shop's documents name it: VAT, GST, Sales tax. */
        public string $name,
        public ?Percentage $standard = null,
        public array $reduced = [],
        public ?Percentage $superReduced = null,
        public array $regional = [],
        public bool $pricesIncludeTax = true,
        /** What an invoice says when a small business charges no tax, in the country's words; null where none is usual. */
        public ?string $smallBusinessNote = null,
        /** The day the rates were last checked, Y-m-d; null for a country the shop knows nothing of. */
        public ?string $checkedOn = null,
    ) {}

    public function known(): bool
    {
        return $this->system !== TaxSystem::Unknown;
    }

    /** @return list<string> the states with taxes of their own */
    public function states(): array
    {
        return array_values(array_unique(array_map(static fn(RegionalTax $tax): string => $tax->state, $this->regional)));
    }

    /** @return list<RegionalTax> */
    public function taxesOf(string $state): array
    {
        return array_values(array_filter($this->regional, static fn(RegionalTax $tax): bool => $tax->state === $state));
    }
}
