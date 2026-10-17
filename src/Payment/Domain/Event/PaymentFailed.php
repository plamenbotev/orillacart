<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * The provider declined or failed the payment.
 */
final readonly class PaymentFailed implements DomainEvent
{
    public function __construct(
        public int $orderId,
        public int $paymentId,
        public string $gateway,
        public ?string $transactionId = null,
    ) {}
}
