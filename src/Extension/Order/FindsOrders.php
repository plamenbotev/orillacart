<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * Finds orders by text the add-on keeps about them, for the search box of the Orders screen: a warehouse's
 * reference, a marketplace's order id. The shop asks for three characters or more and lists what it gives
 * beside the orders it finds itself.
 */
interface FindsOrders
{
    /** Lowercase letters, digits and underscores, up to 40; unique among order searches. */
    public function id(): string;

    /**
     * @param string $text what the shop manager typed, trimmed
     *
     * @return list<int> the numbers of the orders it matches; the shop takes up to 500
     */
    public function findOrders(string $text): array;
}
