<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Coupons;

use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponRepository;

final class InMemoryCouponRepository implements CouponRepository
{
    /** @var array<int, Coupon> */
    public array $coupons = [];
    private int $nextId = 1;

    public function find(int $id): ?Coupon
    {
        return $this->coupons[$id] ?? null;
    }

    public function findByCodes(array $codes, bool $lock = false): array
    {
        $found = [];
        foreach ($this->coupons as $coupon) {
            if (\in_array($coupon->code(), $codes, true)) {
                $found[$coupon->code()] = $coupon;
            }
        }

        return $found;
    }

    public function search(?string $search, int $page, int $perPage): array
    {
        $found = array_values(array_filter(array_reverse($this->coupons), static fn(Coupon $coupon): bool => $search === null || str_contains($coupon->code(), strtoupper($search))));

        return [\array_slice($found, ($page - 1) * $perPage, $perPage), \count($found)];
    }

    public function save(Coupon $coupon): void
    {
        $id = new \ReflectionProperty(Coupon::class, 'id');
        if ($id->getValue($coupon) === null) {
            $id->setValue($coupon, $this->nextId++);
        }
        $this->coupons[$coupon->id()] = $coupon;
    }

    public function delete(Coupon $coupon): void
    {
        unset($this->coupons[$coupon->id()]);
    }
}
