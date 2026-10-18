<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

use OrillaCart\Cart\Domain\Cart;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: put a product, with the customer's choice of options, in the cart.
 */
final readonly class AddToCart
{
    /**
     * @param list<int> $optionIds     for a product with variations, exactly one variation's options
     * @param list<int> $bundleChoices for a bundle, the variations chosen and the optional products added
     */
    public function __construct(
        #[Assert\Positive]
        public int $productId,
        #[Assert\All([new Assert\Positive()])]
        public array $optionIds = [],
        #[Assert\Range(min: 1, max: Cart::MAX_QUANTITY)]
        public int $quantity = 1,
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Positive()])]
        public array $bundleChoices = [],
    ) {}
}
