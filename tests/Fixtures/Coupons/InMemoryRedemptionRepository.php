<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Coupons;

use OrillaCart\Coupons\Domain\CouponRedemption;
use OrillaCart\Coupons\Domain\RedemptionRepository;

final class InMemoryRedemptionRepository implements RedemptionRepository
{
    /** @var list<CouponRedemption> */
    public array $redemptions = [];

    public function countByCustomer(int $couponId, ?int $customerId, string $email): int
    {
        $email = mb_strtolower($email);

        return \count(array_filter($this->redemptions, static function (CouponRedemption $redemption) use ($couponId, $customerId, $email): bool {
            $customer = new \ReflectionProperty(CouponRedemption::class, 'customerId');
            $stored = new \ReflectionProperty(CouponRedemption::class, 'email');

            return $redemption->couponId() === $couponId && (($email !== '' && $stored->getValue($redemption) === $email) || ($customerId !== null && $customer->getValue($redemption) === $customerId));
        }));
    }

    public function ofOrder(int $orderId): array
    {
        return array_values(array_filter($this->redemptions, static fn(CouponRedemption $redemption): bool => $redemption->orderId() === $orderId));
    }

    public function save(CouponRedemption $redemption): void
    {
        if (!\in_array($redemption, $this->redemptions, true)) {
            $this->redemptions[] = $redemption;
        }
    }

    public function delete(CouponRedemption $redemption): void
    {
        $this->redemptions = array_values(array_filter($this->redemptions, static fn(CouponRedemption $kept): bool => $kept !== $redemption));
    }
}
