<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * The provider says the money of an order is in question after it was paid: the payment was reversed, the
 * customer opened a dispute, or a refund the provider had accepted did not go through. The shop has to look.
 */
final readonly class PaymentInQuestion implements DomainEvent
{
    public function __construct(
        public int $orderId,
        public string $gateway,
        /** What the provider said, for the order's history and the shop's email. */
        public string $reason,
    ) {}
}
