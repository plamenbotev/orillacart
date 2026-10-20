<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * One tax rate over a whole bill: what it charges on the items, on shipping and on fees, each rounded to the
 * currency.
 */
final readonly class BilledTax
{
    public function __construct(
        public string $name,
        public string $percent,
        public Money $items,
        public Money $shipping,
        public Money $fees,
    ) {}

    public function total(): Money
    {
        return $this->items->add($this->shipping)->add($this->fees);
    }
}
