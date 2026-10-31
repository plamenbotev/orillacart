<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Cart;

/**
 * A catalog product a cart line type keeps in the cart, with its options and how many.
 */
final readonly class KeptItem
{
    /**
     * @param list<int> $optionIds the options chosen, a variation's for a product with variations
     */
    public function __construct(
        public int $productId,
        public int $quantity,
        public array $optionIds = [],
    ) {}
}
