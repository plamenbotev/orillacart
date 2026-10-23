<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * One rate on one charge of a bill: what it is charged on, before tax, and its share of what the rate bills, in
 * the currency's decimals.
 */
final readonly class ChargedTax
{
    public function __construct(
        public Tax $tax,
        public Money $taxable,
        public Money $amount,
    ) {}
}
