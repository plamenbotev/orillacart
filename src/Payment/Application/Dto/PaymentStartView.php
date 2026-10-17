<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * A started payment: offline when there is nowhere to send the customer, otherwise the provider's page.
 */
final readonly class PaymentStartView
{
    public function __construct(
        public int $paymentId,
        public ?string $redirectUrl,
    ) {}

    public function isOffline(): bool
    {
        return $this->redirectUrl === null;
    }
}
