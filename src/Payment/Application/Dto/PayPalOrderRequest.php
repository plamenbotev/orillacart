<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

use OrillaCart\Extension\Payment\PaymentAddress;

/**
 * A PayPal order to create (Orders API v2, intent CAPTURE) for the whole amount of a shop order.
 */
final readonly class PayPalOrderRequest
{
    /**
     * Amounts are formatted with the decimals PayPal allows for the currency.
     *
     * @param string                                                                  $requestId idempotency key: the same request id never creates a second PayPal order
     * @param string                                                                  $customId  the shop's order number, which PayPal shows the seller and sends back
     * @param list<array{name: string, sku: ?string, quantity: int, unitAmount: string}> $items     only with a breakdown
     * @param ?array{itemTotal: string, tax: string, shipping: string, discount: string, handling: string} $breakdown adds up to the amount
     * @param ?PaymentAddress                                                          $shipTo    where the goods go; null when nothing ships
     */
    public function __construct(
        public string $requestId,
        public string $invoiceId,
        public string $customId,
        public string $description,
        public string $amount,
        public string $currency,
        public string $returnUrl,
        public string $cancelUrl,
        public array $items = [],
        public ?array $breakdown = null,
        public ?PaymentAddress $shipTo = null,
    ) {}
}
