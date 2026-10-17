<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * The money for the order is the shop's.
 */
final readonly class PaymentCompleted implements DomainEvent
{
    public function __construct(
        public int $orderId,
        public int $paymentId,
        public string $gateway,
        public ?string $transactionId = null,
    ) {}
}
