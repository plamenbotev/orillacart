<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * How an order ships, kept by name: the carrier or rate may change or go later.
 */
final readonly class ShippingChoice
{
    public function __construct(
        public int $rateId,
        public string $carrier,
        public string $rate,
        public Money $price,
        public Money $tax,
    ) {}
}
