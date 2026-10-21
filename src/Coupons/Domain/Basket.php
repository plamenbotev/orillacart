<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * What coupons are checked against: the items, who orders them, and where they go.
 */
final readonly class Basket
{
    /**
     * @param list<BasketLine> $lines
     * @param string|null      $country the shipping country, when known; country rules wait for it
     * @param bool             $firstOrder the customer never ordered before
     * @param Money|null       $itemsSubtotal what the items cost before any discount, for order size rules;
     *                                        the lines' totals when not given
     */
    public function __construct(
        public array $lines,
        public string $currency,
        public ?string $country = null,
        public ?int $customerId = null,
        public ?string $email = null,
        public bool $firstOrder = true,
        public ?Money $itemsSubtotal = null,
        /** Units of the basket's currency for one of the shop's: what coupons take off, and order sizes, are the shop's. */
        public string $rate = '1',
    ) {}

    /** An amount of the shop's, in the basket's currency. */
    public function inCurrency(Money $amount): Money
    {
        return $this->rate === '1' ? $amount : $amount->at($this->rate);
    }

    /** What the items cost before any discount, as order size rules judge it. */
    public function subtotal(): Money
    {
        return $this->itemsSubtotal ?? array_reduce($this->lines, static fn(Money $sum, BasketLine $line): Money => $sum->add($line->total), Money::zero());
    }
}
