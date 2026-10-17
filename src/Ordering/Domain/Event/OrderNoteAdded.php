<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The shop added a note to an order. Announced as the action orillacart_order_note_added with the order number,
 * the note and whether it is for the customer.
 */
final readonly class OrderNoteAdded implements PublicEvent
{
    public function __construct(
        public int $orderId,
        public string $text,
        public bool $forCustomer,
    ) {}

    public function hookName(): string
    {
        return 'order_note_added';
    }

    public function hookArguments(): array
    {
        return [$this->orderId, $this->text, $this->forCustomer];
    }
}
