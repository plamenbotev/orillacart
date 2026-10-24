<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

/**
 * A coupon that holds for an order, and what it takes off.
 */
final readonly class AppliedCoupon
{
    /**
     * @param string                $amount what it takes off the items, in all
     * @param array<int, string>    $lines  what it takes off each item, by its index in the order
     */
    public function __construct(
        public string $code,
        public string $description,
        public string $amount,
        public bool $freeShipping,
        public array $lines,
        /** An automatic discount: customers see the description, not the code. */
        public bool $automatic = false,
        /** Its free shipping covers every way to ship; otherwise the cheapest. */
        public bool $freeShippingEveryRate = false,
    ) {}
}
