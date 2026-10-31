<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Coupons;

use OrillaCart\Coupons\Domain\CouponRedemption;
use OrillaCart\Coupons\Domain\RedemptionRepository;

final class InMemoryRedemptionRepository implements RedemptionRepository
{
    /** @var list<CouponRedemption> */
    public array $redemptions = [];

    public function countByCustomer(int|array $couponIds, ?int $customerId, string $email, bool $current = false): int
    {
        $email = mb_strtolower($email);

        return \count(array_filter($this->redemptions, static function (CouponRedemption $redemption) use ($couponIds, $customerId, $email): bool {
            $customer = new \ReflectionProperty(CouponRedemption::class, 'customerId');
            $stored = new \ReflectionProperty(CouponRedemption::class, 'email');

            return \in_array($redemption->couponId(), (array) $couponIds, true) && (($email !== '' && $stored->getValue($redemption) === $email) || ($customerId !== null && $customer->getValue($redemption) === $customerId));
        }));
    }

    /** @var list<array{?int, string}> the turns at first orders taken */
    public array $turns = [];

    public function takeFirstOrderTurn(?int $customerId, string $email): void
    {
        $this->turns[] = [$customerId, mb_strtolower($email)];
    }

    public function ofOrder(int $orderId): array
    {
        return array_values(array_filter($this->redemptions, static fn(CouponRedemption $redemption): bool => $redemption->orderId() === $orderId));
    }

    public function orderIds(?array $couponIds = null): array
    {
        $ids = [];
        foreach ($this->redemptions as $redemption) {
            if ($couponIds === null || \in_array($redemption->couponId(), $couponIds, true)) {
                $ids[$redemption->orderId()] = true;
            }
        }

        return array_keys($ids);
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

    public function forgetCustomer(int $customerId): void
    {
        $customer = new \ReflectionProperty(CouponRedemption::class, 'customerId');
        foreach ($this->redemptions as $record) {
            if ($customer->getValue($record) === $customerId) {
                $customer->setValue($record, null);
            }
        }
    }
}
