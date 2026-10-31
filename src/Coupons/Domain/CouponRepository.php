<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

interface CouponRepository
{
    public function find(int $id): ?Coupon;

    /**
     * @param list<string> $codes normalized
     * @param bool         $lock  locks their rows until the transaction ends, so usage limits hold for orders
     *                            placed at the same time
     *
     * @return array<string, Coupon> by code
     */
    public function findByCodes(array $codes, bool $lock = false): array;

    /**
     * @return array{list<Coupon>, int} the page, newest first, and how many match
     */
    public function search(?string $search, int $page, int $perPage, bool $automatic = false): array;

    /** @return list<int> the coupons for a first order only, codes and automatic discounts */
    public function firstOrderOnlyIds(): array;

    /** @return list<Coupon> the automatic discounts switched on, oldest first */
    public function automatic(): array;

    /** @return list<Coupon> the coupons switched on that shoppers are told of, automatic or codes, oldest first */
    public function advertised(): array;

    public function save(Coupon $coupon): void;

    public function delete(Coupon $coupon): void;
}
