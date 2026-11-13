<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain\Event;

/**
 * An order was saved from the cart, its payment about to start: what was waiting on the cart (a shopper's agent's
 * checkout session) learns its order before the cart is emptied.
 */
final readonly class CartOrdered
{
    public function __construct(public int $cartId, public int $orderId) {}
}
