<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * One line of an order a fee is asked for.
 */
final readonly class FeeItem
{
    /**
     * @param string $total the line's price for its quantity, less what coupons take off it
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public string $name,
        public int $quantity,
        public string $total,
    ) {}
}
