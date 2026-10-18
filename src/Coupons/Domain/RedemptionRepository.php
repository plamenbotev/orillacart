<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

interface RedemptionRepository
{
    /** How often a customer used the coupon: by account, or by email for a guest. */
    public function countByCustomer(int $couponId, ?int $customerId, string $email): int;

    /** @return list<CouponRedemption> */
    public function ofOrder(int $orderId): array;

    public function save(CouponRedemption $redemption): void;

    public function delete(CouponRedemption $redemption): void;
}
