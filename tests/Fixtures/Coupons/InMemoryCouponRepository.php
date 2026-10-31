<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Coupons;

use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponRepository;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;

final class InMemoryCouponRepository implements CouponRepository
{
    /** @var array<int, Coupon> */
    public array $coupons = [];
    private int $nextId = 1;

    public function __construct(private readonly ?ImmediateTransactions $transactions = null) {}

    public function find(int $id): ?Coupon
    {
        return $this->coupons[$id] ?? null;
    }

    public function findByCodes(array $codes, bool $lock = false): array
    {
        if ($lock && $codes !== []) {
            $this->transactions?->requireOpen();
        }
        $found = [];
        foreach ($this->coupons as $coupon) {
            if (\in_array($coupon->code(), $codes, true)) {
                $found[$coupon->code()] = $coupon;
            }
        }

        return $found;
    }

    public function search(?string $search, int $page, int $perPage, bool $automatic = false): array
    {
        $found = array_values(array_filter(array_reverse($this->coupons), static fn(Coupon $coupon): bool => $coupon->isAutomatic() === $automatic && ($search === null || str_contains($coupon->code(), strtoupper($search)))));

        return [\array_slice($found, ($page - 1) * $perPage, $perPage), \count($found)];
    }

    public function automatic(): array
    {
        return array_values(array_filter($this->coupons, static fn(Coupon $coupon): bool => $coupon->isAutomatic() && $coupon->terms()->active));
    }

    public function advertised(): array
    {
        return array_values(array_filter($this->coupons, static fn(Coupon $coupon): bool => $coupon->isAdvertised() && $coupon->terms()->active));
    }

    public function firstOrderOnlyIds(): array
    {
        return array_values(array_map(static fn(Coupon $coupon): int => $coupon->id(), array_filter($this->coupons, static fn(Coupon $coupon): bool => $coupon->terms()->firstOrderOnly)));
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
