<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * Money went back to the customer for an order, all of it or a part. Announced as the action
 * orillacart_order_refunded with the order number, the amount and whether the order is now refunded in full.
 */
final readonly class OrderRefunded implements PublicEvent
{
    /**
     * @param array<int, int>                                             $quantities items refunded by line position
     * @param list<array{name: string, percent: string, amount: string}> $taxes      the tax inside the amount, by rate
     */
    public function __construct(
        public int $orderId,
        public string $amount,
        public bool $full,
        public array $quantities = [],
        public bool $tellCustomer = true,
        public string $tax = '0',
        public array $taxes = [],
        /** How it went back when not as money: "store_credit", or "gift_card" for a guest; null for money. */
        public ?string $credit = null,
    ) {}

    public function hookName(): string
    {
        return 'order_refunded';
    }

    public function hookArguments(): array
    {
        return [$this->orderId, $this->amount, $this->full];
    }
}
