<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * What a gateway needs to start a payment: the amount, and where the provider sends the customer back.
 */
final readonly class PaymentSession
{
    /**
     * @param string $amount          a decimal number with as many decimals as the shop uses: "42.50"
     * @param string $returnUrl       where the provider sends the customer after paying (HandlesReturns)
     * @param string $cancelUrl       where it sends a customer who gave up
     * @param string $notificationUrl where the provider calls the shop back (HandlesNotifications)
     */
    public function __construct(
        public int $paymentId,
        public int $orderNumber,
        public string $amount,
        public string $currency,
        public string $returnUrl,
        public string $cancelUrl,
        public string $notificationUrl = '',
    ) {}
}
