<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class CouponNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct(\sprintf('Coupon %d does not exist.', $id), 'orillacart_coupon_not_found');
    }
}
