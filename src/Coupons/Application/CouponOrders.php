<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application;

use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponRepository;
use OrillaCart\Coupons\Domain\RedemptionRepository;
use OrillaCart\Ordering\Application\OrderLookup;
use OrillaCart\Ordering\Application\OrderMark;

/**
 * The orders that used coupons, for the shop's order search (by a code) and filters ("used a coupon").
 */
final readonly class CouponOrders implements OrderLookup, OrderMark
{
    /** Codes the search looks at: more would be a search for coupons, not for an order. */
    private const int MAX_COUPONS = 50;

    public function __construct(
        private CouponRepository $coupons,
        private RedemptionRepository $redemptions,
    ) {}

    public function ordersMatching(string $text): array
    {
        [$coupons] = $this->coupons->search($text, 1, self::MAX_COUPONS);

        return \array_slice($this->redemptions->orderIds(array_map(static fn(Coupon $coupon): int => $coupon->id(), $coupons)), 0, self::MAX_FOUND);
    }

    public function key(): string
    {
        return 'coupon';
    }

    public function label(): string
    {
        return 'Used a coupon';
    }

    public function orderIds(): array
    {
        return $this->redemptions->orderIds();
    }
}
