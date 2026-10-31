<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

/**
 * What a discount gives with the cart as it is: the product, its options, how many, and the discount's code and
 * description.
 */
final readonly class GivenItem
{
    /**
     * @param list<int> $optionIds
     * @param list<int> $bundleChoices
     */
    public function __construct(
        public string $code,
        public string $label,
        public int $productId,
        public array $optionIds,
        public array $bundleChoices,
        public int $quantity,
    ) {}
}
