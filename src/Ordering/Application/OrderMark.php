<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

/**
 * Something an area knows an order has, for the shop's order filters: "used a coupon", "has a return". Tagged
 * by the Kernel, so the container finds every one.
 */
interface OrderMark
{
    public const string MARK_TAG = 'orillacart.order_mark';

    /** Unique among marks and the order's own facts: "coupon". */
    public function key(): string;

    /** As the filter shows it, translated: "Used a coupon". */
    public function label(): string;

    /**
     * @return list<int> ids of the orders that have it
     */
    public function orderIds(): array;
}
