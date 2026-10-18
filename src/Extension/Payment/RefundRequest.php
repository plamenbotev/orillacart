<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * A refund of a payment the gateway took.
 */
final readonly class RefundRequest
{
    /**
     * @param string      $id            the shop's id of the refund, the same each time it is tried: "orillacart-refund-12"
     * @param string|null $reference     the payment's reference, as PaymentStart::redirect() gave it
     * @param string|null $transactionId the provider's id of the money moved, as the payment's outcome gave it
     * @param string      $amount        a decimal number with as many decimals as the shop uses: "12.50"
     * @param string      $reason        what the shop wrote, for the provider's records; may be empty
     */
    public function __construct(
        public string $id,
        public int $orderNumber,
        public ?string $reference,
        public ?string $transactionId,
        public string $amount,
        public string $currency,
        public string $reason = '',
    ) {}
}
