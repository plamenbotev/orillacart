<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

use OrillaCart\Shared\Domain\Money;

/**
 * The coupons that hold for an order, and why the others do not.
 */
final readonly class AppliedDiscounts
{
    /**
     * @param list<AppliedCoupon>   $coupons in the order they were entered
     * @param array<string, string> $refused why, by code
     * @param list<array{productId: int, quantity: int}> $gifts free gifts that hold but are not in the order yet:
     *                                                          checkout adds them and prices the order again
     */
    public function __construct(
        public array $coupons = [],
        public array $refused = [],
        public array $gifts = [],
    ) {}

    /** What the coupons take off the item, by its index in the order. */
    public function forLine(int $index): Money
    {
        $sum = Money::zero();
        foreach ($this->coupons as $coupon) {
            $sum = $sum->add(Money::of($coupon->lines[$index] ?? '0'));
        }

        return $sum;
    }

    public function freeShipping(): bool
    {
        foreach ($this->coupons as $coupon) {
            if ($coupon->freeShipping) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn(AppliedCoupon $coupon): string => $coupon->code, $this->coupons);
    }
}
