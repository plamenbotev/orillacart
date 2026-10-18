<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\Bundle;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a bundle is made of, and how it is priced: its own price (fixed), or its items' prices added up with a
 * percentage off (sum).
 */
final readonly class SaveBundle
{
    /**
     * @param list<SaveBundleItem> $items
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 50, minMessage: 'A bundle holds at least one item.')]
        #[Assert\Valid]
        public array $items,
        #[Assert\Choice(choices: [Bundle::FIXED, Bundle::SUM])]
        public string $pricing = Bundle::FIXED,
        #[Assert\Regex(SaveProduct::AMOUNT, message: SaveProduct::AMOUNT_MESSAGE)]
        public ?string $discount = null,
    ) {}
}
