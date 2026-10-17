<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * The customer gave up paying at the provider.
 */
final readonly class PaymentCancelled implements DomainEvent
{
    public function __construct(
        public int $orderId,
        public int $paymentId,
        public string $gateway,
        public ?string $transactionId = null,
    ) {}
}
