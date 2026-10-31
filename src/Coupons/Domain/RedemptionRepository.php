<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

interface RedemptionRepository
{
    /**
     * How often a customer used the coupon, or any of the coupons: by account, or by email for a guest.
     *
     * @param int|list<int> $couponIds
     * @param bool          $current   counted as committed now, under a shared lock, rather than as the transaction
     *                                 first saw them: for a count taken while the coupon is locked
     */
    public function countByCustomer(int|array $couponIds, ?int $customerId, string $email, bool $current = false): int;

    /**
     * Waits for the customer's turn at first orders, by account and by email, and holds it until the transaction
     * ends: of two first orders placed at once, the second counts its codes only once the first committed its
     * uses, whatever codes each used. Other customers' orders do not wait.
     */
    public function takeFirstOrderTurn(?int $customerId, string $email): void;

    /** @return list<CouponRedemption> */
    public function ofOrder(int $orderId): array;

    /**
     * Orders that redeemed any of the coupons, or any coupon at all.
     *
     * @param list<int>|null $couponIds null for any coupon
     *
     * @return list<int> order ids
     */
    public function orderIds(?array $couponIds = null): array;

    public function save(CouponRedemption $redemption): void;

    public function delete(CouponRedemption $redemption): void;
}
