<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * What a gateway needs to start a payment: the amount, and where the provider sends the customer back. What
 * the order holds and where it ships come along for providers that show or protect them.
 */
final readonly class PaymentSession
{
    /**
     * @param string $amount          a decimal number with as many decimals as the shop uses: "42.50"
     * @param string $returnUrl       where the provider sends the customer after paying (HandlesReturns)
     * @param string $cancelUrl       where it sends a customer who gave up
     * @param string                $notificationUrl where the provider calls the shop back (HandlesNotifications)
     * @param list<PaymentItem>     $items           empty when the lines do not add up to the breakdown's item total
     * @param ?PaymentBreakdown     $breakdown       null when the amount is not what the order's parts add up to
     * @param ?PaymentAddress       $shipTo          null when nothing ships
     */
    public function __construct(
        public int $paymentId,
        public int $orderNumber,
        public string $amount,
        public string $currency,
        public string $returnUrl,
        public string $cancelUrl,
        public string $notificationUrl = '',
        public array $items = [],
        public ?PaymentBreakdown $breakdown = null,
        public ?PaymentAddress $shipTo = null,
    ) {}
}
