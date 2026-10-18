<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * A coupon as the order used it: its code and description then, and what it took off the items.
 */
final readonly class OrderDiscount
{
    public function __construct(
        public string $code,
        public string $description,
        public Money $amount,
        public bool $freeShipping = false,
    ) {}
}
