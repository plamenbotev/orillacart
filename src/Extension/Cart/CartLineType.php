<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Cart;

/**
 * Lines an add-on keeps in the cart for what it holds, kept in step whenever the cart changes: catalog products
 * (a sample with every order), priced as the catalog prices them and sold as order lines of the type; or prices of
 * their own with no product (a deposit for each bottle), sold as charges. Their quantity is the type's.
 */
interface CartLineType
{
    /** Lowercase letters, digits and underscores, up to 40; unique among cart line types. */
    public function id(): string;

    /** Shown with each of its lines, in the shopper's language: "Deposit". */
    public function label(): string;

    /** Whether the shopper may remove its lines; the cart then keeps none of the type until they ask again. */
    public function removable(): bool;

    /**
     * What the type keeps in the cart as it is: none for nothing. The cart's own lines only, not the type's.
     *
     * @return list<KeptItem|KeptCharge>
     */
    public function lines(CheckedCart $cart): array;
}
