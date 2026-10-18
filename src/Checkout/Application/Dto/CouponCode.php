<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: a coupon code the customer enters.
 */
final readonly class CouponCode
{
    public function __construct(
        #[Assert\NotBlank(message: 'Enter a code.')]
        #[Assert\Length(max: 40)]
        public string $code,
    ) {}
}
