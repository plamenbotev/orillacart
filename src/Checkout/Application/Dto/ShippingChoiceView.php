<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * A way to ship the order, with its price before tax, its tax and both together, rounded to the currency.
 */
final readonly class ShippingChoiceView
{
    public function __construct(
        public string $id,
        public string $carrier,
        public string $name,
        public string $price,
        public string $tax,
        public string $total,
    ) {}
}
