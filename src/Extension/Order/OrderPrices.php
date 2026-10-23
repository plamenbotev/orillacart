<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * Changes the prices of an order the shop keeps on hold, waiting for an offline payment: the order is priced
 * again with the change, its taxes worked out again, and its history says who changed it and why. A paid order
 * keeps its prices: money goes back by refunds. Since version 9.
 */
interface OrderPrices
{
    /**
     * @param string   $source the add-on's id, which the order keeps with the change
     * @param string   $amount decimal, below zero to lower the price: "-5.00"
     * @param int|null $line   the line's place among the order's items (OrderSummary::$lines); null for the whole
     *                         order, spread over its items
     *
     * @throws \InvalidArgumentException for an order that is not on hold, a line it does not have, or an amount
     *                                   that takes off more than the line costs
     */
    public function adjust(int $number, string $source, string $label, string $amount, ?int $line = null, string $reason = ''): void;
}
