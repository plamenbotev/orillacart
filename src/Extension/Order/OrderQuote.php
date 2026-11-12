<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * What an order would cost and how it could ship and be paid, before it is placed (API 27): priced as the shop
 * prices one it takes by hand, nothing taken or written.
 */
final readonly class OrderQuote
{
    /**
     * @param bool                $needsShipping   whether its items ship: without, it takes no shipping option
     * @param list<ShippingChoice> $shippingOptions the ways it ships to its address, cheapest first as the shop lists them
     * @param string|null         $shippingOption  the one the request chose, or the shop's first, when it ships
     * @param list<string>        $payments        the payment methods the shop offers for its billing country
     */
    public function __construct(
        public bool $needsShipping,
        public array $shippingOptions,
        public ?string $shippingOption,
        public array $payments,
        public string $subtotal,
        public string $shipping,
        public string $tax,
        public string $total,
        public string $currency,
    ) {}
}
