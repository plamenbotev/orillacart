<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

/**
 * What the setup knows of a country, for its questions: the money and units it starts with, how it taxes, what
 * its law asks of a shop.
 */
final readonly class CountryProfileView
{
    /**
     * @param list<string>                                     $reduced  reduced rates, highest first
     * @param list<array{state: string, name: string, percent: string}> $regional taxes by state or province
     * @param list<string>                                     $currencies every currency a shop can keep, for the list
     */
    public function __construct(
        public string $country,
        public bool $inEu,
        public ?string $currency,
        public string $weightUnit,
        public string $dimensionUnit,
        public string $taxSystem,
        public string $taxName,
        public ?string $standard,
        public array $reduced,
        public ?string $superReduced,
        public array $regional,
        public bool $pricesIncludeTax,
        public ?string $smallBusinessNote,
        public ?string $checkedOn,
        public ?int $withdrawalDays,
        public bool $legalNotice,
        public string $legalNoticeTitle,
        public bool $consumerCheckout,
        public ?string $eInvoices,
        public array $currencies,
        /** Whether its banks number accounts by IBAN. */
        public bool $iban = false,
    ) {}
}
