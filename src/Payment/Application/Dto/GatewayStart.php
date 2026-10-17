<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * How a gateway started a payment: offline (the shop waits for the money), or at a provider the customer
 * must be sent to, with the provider's id for the payment.
 */
final readonly class GatewayStart
{
    private function __construct(
        public ?string $redirectUrl,
        public ?string $reference,
    ) {}

    public static function offline(): self
    {
        return new self(null, null);
    }

    public static function redirect(string $url, string $reference): self
    {
        return new self($url, $reference);
    }
}
