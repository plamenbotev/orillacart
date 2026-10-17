<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * A webhook call from PayPal as it arrived: the transmission headers PayPal signs, and the raw body.
 */
final readonly class PayPalNotification
{
    /**
     * @param array<string, string> $headers lower-case names: paypal-transmission-id, paypal-transmission-sig...
     */
    public function __construct(
        public array $headers,
        public string $body,
    ) {}
}
