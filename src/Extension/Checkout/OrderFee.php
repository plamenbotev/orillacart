<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * Adds a fee to an order at checkout: a surcharge for a way to pay, for a bulky order, for a delivery that
 * needs a crane. Checkout shows it in the totals, the order keeps it with its label, and the receipt, the
 * emails and the invoice show it. Since version 5.
 *
 * A fee that throws is logged and left out, and an order placed without it says so in a note for the shop; one
 * that implements FailsClosed refuses the order instead, asking to try again in a moment.
 */
interface OrderFee
{
    /** Lowercase letters, digits and underscores, up to 40; unique among fees. */
    public function id(): string;

    /** The fee for this order, or null for none. */
    public function fee(FeeQuery $query): ?Fee;
}
