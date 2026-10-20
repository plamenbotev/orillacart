<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * The order a fee is asked for, as checkout has it so far: amounts as the shop's prices are, before tax or
 * including it.
 */
final readonly class FeeQuery
{
    /**
     * @param list<FeeItem>  $items
     * @param string         $subtotal       the items, less what coupons take off
     * @param string         $shipping       what shipping costs; "0" for an order that ships nothing
     * @param string|null    $shippingOption the way to ship chosen, such as "rate:12" or "carrier:5:express"
     * @param string|null    $paymentMethod  the gateway the customer chose to pay with; null before they choose,
     *                                       and for an order that costs nothing
     * @param string|null    $country        where the order goes, or where it is billed when it ships nothing
     */
    public function __construct(
        public array $items,
        public string $subtotal,
        public string $shipping,
        public ?string $shippingOption,
        public ?string $paymentMethod,
        public ?string $country,
        public ?int $customerId,
        public string $currency,
    ) {}
}
