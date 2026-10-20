<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * Checks an address the customer entered at checkout against a register of addresses (Loqate, Google, the post
 * office's), and suggests a corrected one: the customer takes it or keeps theirs, and the order notes when they
 * kept one the check could not find or would have corrected. Checks run in the order they were registered; the
 * first with something to say is heard. Since version 5.
 *
 * A check that throws is logged and left out: the customer is never kept from ordering by a register that is down.
 */
interface AddressCheck
{
    /** Lowercase letters, digits and underscores, up to 40; unique among address checks. */
    public function id(): string;

    /** Null when the address is fine, or the check cannot tell. */
    public function check(CheckedAddress $address): ?AddressSuggestion;
}
