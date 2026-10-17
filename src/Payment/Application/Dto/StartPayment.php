<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * Command: start paying for a placed order with a payment method.
 */
final readonly class StartPayment
{
    public function __construct(
        public int $orderId,
        public int $orderNumber,
        public string $gateway,
        public string $amount,
        public string $currency,
        /** Where the customer lands when the payment ends. */
        public string $receiptUrl,
    ) {}
}
