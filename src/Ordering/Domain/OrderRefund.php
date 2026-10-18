<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * Money given back for an order: how much, and which items it covers, if any. A refund of an amount alone, such
 * as a goodwill gesture, names no items.
 */
final readonly class OrderRefund
{
    /**
     * @param array<int, int> $quantities items refunded by line position
     * @param bool            $shipping   whether it covers the shipping charge
     * @param bool            $restock    whether the refunded items go back in stock
     */
    public function __construct(
        public Money $amount,
        public array $quantities = [],
        public bool $shipping = false,
        public bool $restock = false,
    ) {}
}
