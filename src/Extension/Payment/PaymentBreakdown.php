<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * What the amount of a payment is made of. The shop gives it only when it adds up exactly: items, plus tax,
 * plus shipping, plus handling, less the discount, is the amount; and the items' unit amounts times their
 * quantities are the item total.
 */
final readonly class PaymentBreakdown
{
    /**
     * @param string $tax      0 when the shop's prices include it
     * @param string $handling the order's fees (since version 5)
     */
    public function __construct(
        public string $itemTotal,
        public string $tax,
        public string $shipping,
        public string $discount,
        public string $handling = '0',
    ) {}
}
