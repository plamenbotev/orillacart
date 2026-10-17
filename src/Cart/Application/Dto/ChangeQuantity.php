<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

use OrillaCart\Cart\Domain\Cart;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: set how many of a cart line the customer wants; zero removes the line.
 */
final readonly class ChangeQuantity
{
    public function __construct(
        #[Assert\Range(min: 0, max: Cart::MAX_QUANTITY)]
        public int $quantity,
    ) {}
}
