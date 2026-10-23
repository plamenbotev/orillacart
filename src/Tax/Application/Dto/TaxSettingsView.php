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
    ) {}

    public static function fromSettings(TaxSettings $settings): self
    {
        return new self($settings->basis->value, $settings->defaultGroupId, TaxBasis::values(), $settings->pricesIncludeTax, $settings->reverseCharge, $settings->roundsEachLine, $settings->originRegions);
    }
}
