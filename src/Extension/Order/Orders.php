<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * Reads the shop's orders, for add-ons reacting to the order actions (orillacart_order_placed and the others pass
 * the order number), and marks them for the add-ons' order filters.
 */
interface Orders
{
    public function find(int $number): ?OrderSummary;

    /**
     * Marks the order for the order filter with this id; marking it again changes nothing. Since version 8.
     *
     * @param string $filterId an OrderFilter's id: lowercase letters, digits and underscores, up to 40
     *
     * @return bool false when the shop has no such order
     *
     * @throws \InvalidArgumentException for a filter id that cannot be one
     */
    public function mark(int $number, string $filterId): bool;

    /** Takes the mark off; an order without it is left as it is. Since version 8. */
    public function unmark(int $number, string $filterId): void;

    /** Since version 8. */
    public function isMarked(int $number, string $filterId): bool;
}
