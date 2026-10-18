<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * Reads the shop's orders, for add-ons reacting to the order actions (orillacart_order_placed and the others pass
 * the order number).
 */
interface Orders
{
    public function find(int $number): ?OrderSummary;
}
