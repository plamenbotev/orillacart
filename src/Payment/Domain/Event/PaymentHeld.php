<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * The provider holds the money for review: the order waits, it is not abandoned.
 */
final readonly class PaymentHeld implements DomainEvent
{
    public function __construct(
        public int $orderId,
        public int $paymentId,
        public string $gateway,
        public ?string $transactionId = null,
    ) {}
}
