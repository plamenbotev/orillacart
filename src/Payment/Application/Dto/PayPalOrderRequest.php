<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * A PayPal order to create (Orders API v2, intent CAPTURE) for the whole amount of a shop order.
 */
final readonly class PayPalOrderRequest
{
    /**
     * @param string $amount   formatted with the decimals PayPal allows for the currency
     * @param string $requestId idempotency key: the same request id never creates a second PayPal order
     */
    public function __construct(
        public string $requestId,
        public string $invoiceId,
        public string $description,
        public string $amount,
        public string $currency,
        public string $returnUrl,
        public string $cancelUrl,
    ) {}
}
