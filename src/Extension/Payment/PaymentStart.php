<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * How a gateway started a payment.
 */
final readonly class PaymentStart
{
    private function __construct(
        public ?string $redirectUrl,
        public ?string $reference,
    ) {}

    /** The shop waits for the money, as with a bank transfer. */
    public static function offline(): self
    {
        return new self(null, null);
    }

    /**
     * The customer goes to the provider.
     *
     * @param string $reference the provider's id of the payment, which its returns and notifications name
     */
    public static function redirect(string $url, string $reference): self
    {
        return new self($url, $reference);
    }
}
