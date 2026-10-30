<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

use OrillaCart\Shared\Domain\Money;

/**
 * The sales of a period, as decimal strings. Orders count on the day the shop chose (paid or placed), refunds
 * on the day the money went back.
 */
final readonly class SalesTotals
{
    public string $net;
    public string $totalSales;
    public string $averageOrder;

    /**
     * @param int    $orders       paid orders
     * @param int    $items        items in them
     * @param string $gross        the items' prices, before discounts and tax
     * @param string $discounts    what coupons and automatic discounts took off
     * @param string $refunds      money given back, its tax included
     * @param string $total        what customers paid, before refunds
     * @param string $refundedItems the items' share of the refunds before tax: what net sales lose
     * @param int    $customers    different customers who ordered
     * @param int    $newCustomers of them, those whose first paid order it was
     */
    public function __construct(
        public int $orders = 0,
        public int $items = 0,
        public string $gross = '0',
        public string $discounts = '0',
        public string $refunds = '0',
        public string $shipping = '0',
        public string $taxes = '0',
        public string $total = '0',
        public int $customers = 0,
        public int $newCustomers = 0,
        public string $refundedItems = '0',
    ) {
        // Net sales are the items' before tax: refunded shipping and fees, and refunds' tax, were never in them.
        $this->net = (string) Money::of($gross)->subtract(Money::of($discounts))->subtract(Money::of($refundedItems));
        $this->totalSales = (string) Money::of($total)->subtract(Money::of($refunds));
        $this->averageOrder = $orders === 0 ? '0' : (string) Money::of($total)->proportion(Money::of('1'), Money::of((string) $orders));
    }
}
