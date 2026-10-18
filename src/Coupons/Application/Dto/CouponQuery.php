<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CouponQuery
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $search = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 20,
        /** Automatic discounts instead of codes. */
        public bool $automatic = false,
    ) {}
}
