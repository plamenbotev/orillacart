<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

/**
 * Which emails an order's status change sends. Statuses are the order statuses' values.
 *
 * The customer hears once that the order is real: "received" when it waits for their offline payment, or
 * "processing" when it is paid (or free); a payment confirmed later sends "processing" too. An order the
 * customer gave up at the payment provider sends nothing: they know. The shop hears of each new order once,
 * when it stops waiting for the customer at the provider.
 */
final class EmailRules
{
    /**
     * @return list<EmailKind>
     */
    public static function forStatusChange(string $previous, string $status): array
    {
        $placed = $previous === 'pending_payment';

        return match (true) {
            $status === 'on_hold' && $placed => [EmailKind::OrderReceived, EmailKind::NewOrder],
            $status === 'processing' && $placed => [EmailKind::OrderProcessing, EmailKind::NewOrder],
            $status === 'processing' => [EmailKind::OrderProcessing],
            $status === 'shipped' => [EmailKind::OrderShipped],
            $status === 'completed' => [EmailKind::OrderCompleted],
            $status === 'refunded' => [EmailKind::OrderRefunded],
            $status === 'failed' => [EmailKind::OrderFailed],
            $status === 'cancelled' && !$placed => [EmailKind::OrderCancelled],
            default => [],
        };
    }
}
