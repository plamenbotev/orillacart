<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * A column of the orders export: "Invoice number", "Warehouse". The shop lists it beside its own on the Orders
 * screen, where the shop manager chooses the columns to export.
 */
interface OrderColumn
{
    /** Lowercase letters, digits and underscores, up to 40; unique among order columns, the shop's own too. */
    public function id(): string;

    /** The column's name, for the shop manager and the file's header. */
    public function label(): string;

    /** The order's value in the column; empty for none. */
    public function value(OrderSummary $order): string;
}
