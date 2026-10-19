<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * One line of the order being paid, as a provider lists it to the customer.
 */
final readonly class PaymentItem
{
    /**
     * @param string $unitAmount a decimal number, before tax when the shop's prices leave it out
     */
    public function __construct(
        public string $name,
        public ?string $sku,
        public int $quantity,
        public string $unitAmount,
    ) {}
}
