<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

/**
 * The payment provider could not be reached or refused the request. The message is safe to show to customers;
 * the provider's own answer goes to the log.
 */
final class PaymentProviderUnavailable extends PaymentRuleViolation
{
    public function __construct(string $provider, public readonly string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('%s is not available right now. Try again in a moment or choose another way to pay.', $provider), 'orillacart_payment_provider_unavailable', $previous);
    }
}
