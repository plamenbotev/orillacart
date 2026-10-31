<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * How far a cart is from what a coupon needs of it (Coupon::progress()): the order's size against its smallest,
 * the items it applies to against how many it takes, and whether the items buy-get gives are still to be added.
 * Amounts are in the cart's currency.
 */
final readonly class CouponProgress
{
    public function __construct(
        /** What the order's size rules count now: its items before any discount. */
        public Money $spent,
        /** The smallest order it takes; null for any size. */
        public ?Money $threshold,
        /** The items in the cart it applies to. */
        public int $units,
        /** How many of them it takes. */
        public int $neededUnits,
        /** Buy-get naming the items it gives: none of those is in the cart yet. */
        public bool $needsGiven = false,
    ) {}

    /** How much more to spend; null when the order is large enough. */
    public function short(): ?Money
    {
        return $this->threshold === null || $this->spent->compare($this->threshold) >= 0 ? null : $this->threshold->subtract($this->spent);
    }

    /** How many more of the items it applies to. */
    public function unitsShort(): int
    {
        return max(0, $this->neededUnits - $this->units);
    }

    /** Nothing more to add: the cart has what the coupon needs. */
    public function holds(): bool
    {
        return $this->short() === null && $this->unitsShort() === 0 && !$this->needsGiven;
    }

    /**
     * How near the cart is, from 0 to 1, by the part it lacks most: for showing the nearest first. The items to
     * be given count as one more item.
     */
    public function nearness(): float
    {
        $parts = [];
        if ($this->short() !== null && $this->threshold !== null) {
            $parts[] = $this->threshold->isZero() ? 1.0 : $this->spent->tenThousandths / $this->threshold->tenThousandths;
        }
        if ($this->unitsShort() > 0) {
            $parts[] = $this->units / $this->neededUnits;
        }
        if ($this->needsGiven) {
            $parts[] = $this->units / ($this->units + 1);
        }

        return $parts === [] ? 1.0 : max(0.0, min([1.0, ...$parts]));
    }
}
