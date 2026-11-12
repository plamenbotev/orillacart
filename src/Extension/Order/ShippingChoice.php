<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * One way an order could ship (API 27): its id, which NewOrderRequest::$shippingOption takes, who carries it, and
 * what it costs before tax, its tax and with it, in the shop's currency.
 */
final readonly class ShippingChoice
{
    public function __construct(
        public string $id,
        public string $carrier,
        public string $name,
        public string $price,
        public string $tax,
        public string $total,
        public bool $toPickupPoint = false,
    ) {}
}
