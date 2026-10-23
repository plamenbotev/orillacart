<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * A filter of the Orders screen, under "Details": "Sent to the warehouse", "Gift wrapped". The add-on marks the
 * orders it applies to when that happens (Orders::mark() with the filter's id) and unmarks them when it no longer
 * does; the shop keeps the marks and filters by them in the database, however many orders have one.
 */
interface OrderFilter
{
    /** Lowercase letters, digits and underscores, up to 40; unique among order filters, the shop's own too. */
    public function id(): string;

    /** As the filter shows it: "Sent to the warehouse". */
    public function label(): string;
}
