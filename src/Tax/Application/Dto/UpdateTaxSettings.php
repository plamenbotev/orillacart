<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxBasis;
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
    ) {}
}
