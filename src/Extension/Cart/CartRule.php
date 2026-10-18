<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Cart;

/**
 * Decides whether a cart may hold what it holds: a limit per customer, products that may not ship together, an
 * item only some customers may buy. The shop asks when an item is added or its quantity raised (a refusal of that
 * line refuses the change), when the cart is shown (each refusal is shown, and the cart cannot be ordered) and when
 * the order is placed.
 */
interface CartRule
{
    /** Lowercase letters, digits and underscores, up to 40; unique among cart rules. */
    public function id(): string;

    /**
     * @return list<CartRefusal> none when the cart may be ordered as it is
     */
    public function check(CheckedCart $cart): array;
}
