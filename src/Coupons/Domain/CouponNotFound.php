<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class CouponNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct('Coupon %d does not exist.', 'orillacart_coupon_not_found', values: [$id]);
    }
}
