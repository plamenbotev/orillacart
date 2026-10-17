<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * What a gateway needs to start a payment: the amount, and where the provider sends the customer back.
 */
final readonly class PaymentSession
{
    public function __construct(
        public int $paymentId,
        public int $orderNumber,
        public string $amount,
        public string $currency,
        public string $returnUrl,
        public string $cancelUrl,
    ) {}
}
