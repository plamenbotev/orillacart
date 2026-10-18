<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

final readonly class CouponPageView
{
    /**
     * @param list<CouponView> $coupons newest first
     */
    public function __construct(
        public array $coupons,
        public int $total,
        public int $page,
        public int $pages,
    ) {}
}
