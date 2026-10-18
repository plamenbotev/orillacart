<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * An order's refunds, what is left to refund, and whether the gateway that took the payment can give it back.
 */
final readonly class OrderRefundsView
{
    /**
     * @param string|null      $provider the payment method's name when it refunds through its provider: "PayPal"
     * @param list<RefundView> $refunds  oldest first
     */
    public function __construct(
        public string $refundable,
        public string $currency,
        public bool $paid,
        public ?string $provider,
        public array $refunds,
    ) {}
}
