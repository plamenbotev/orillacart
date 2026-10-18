<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The shop has to look at an order, such as one whose payment was disputed after it was paid. Announced as the
 * action orillacart_order_needs_attention with the order number and the reason.
 */
final readonly class OrderNeedsAttention implements PublicEvent
{
    public function __construct(
        public int $orderId,
        public string $reason,
    ) {}

    public function hookName(): string
    {
        return 'order_needs_attention';
    }

    public function hookArguments(): array
    {
        return [$this->orderId, $this->reason];
    }
}
