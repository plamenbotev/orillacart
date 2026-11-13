<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain\Event;

/**
 * A visitor's cart became a customer's as they logged in: joined into their account's cart and deleted, or the
 * account's cart itself when they had none, under a new token either way. What followed the visitor's cart (a
 * shopper's agent's checkout session) follows the cart it is now. Not announced to WordPress.
 */
final readonly class CartClaimed
{
    public function __construct(
        public int $fromCartId,
        public int $intoCartId,
        public int $customerId,
    ) {}
}
