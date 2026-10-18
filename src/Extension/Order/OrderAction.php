<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * A button on the order screen: "Send to the accounting system", "Print the invoice". The shop runs it when the
 * shop manager presses it and records its answer in the order's history.
 */
interface OrderAction
{
    /** Lowercase letters, digits and underscores, up to 40; unique among order actions. */
    public function id(): string;

    /** The button's text. */
    public function label(): string;

    /** Whether the order screen offers it for this order, such as only once it is paid. */
    public function isAvailable(OrderSummary $order): bool;

    /**
     * @return string what was done, for the order's history and the shop manager: "Invoice 2026-0042 sent."
     *
     * @throws \OrillaCart\Extension\ProviderUnavailable when it could not be done; the shop manager sees its message
     *                                                   and nothing is recorded
     */
    public function run(OrderSummary $order): string;
}
