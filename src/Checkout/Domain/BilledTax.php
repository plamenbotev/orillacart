<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * One tax rate over a whole bill: what it charges on the items and on shipping, each rounded to the currency.
 */
final readonly class BilledTax
{
    public function __construct(
        public string $name,
        public string $percent,
        public Money $items,
        public Money $shipping,
    ) {}

    public function total(): Money
    {
        return $this->items->add($this->shipping);
    }
}
