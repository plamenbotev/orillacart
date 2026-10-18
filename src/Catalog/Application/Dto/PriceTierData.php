<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\PriceTier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * From this many items on: a percentage or an amount off, or a price.
 */
final readonly class PriceTierData
{
    public function __construct(
        #[Assert\Range(min: 1, max: 1000000)]
        public int $minQuantity,
        #[Assert\Choice(choices: [PriceTier::PERCENT, PriceTier::AMOUNT_OFF, PriceTier::FIXED_PRICE])]
        public string $kind,
        #[Assert\Regex(SaveProduct::AMOUNT, message: SaveProduct::AMOUNT_MESSAGE)]
        public string $value,
    ) {}
}
