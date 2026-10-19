<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

/**
 * The emails the shop sends, each from its template in templates/emails/.
 */
enum EmailKind: string
{
    /** To the customer: the order is placed and waits for their offline payment. */
    case OrderReceived = 'order_received';
    /** To the customer: paid (or free), the shop is preparing it. */
    case OrderProcessing = 'order_processing';
    case OrderShipped = 'order_shipped';
    case OrderCompleted = 'order_completed';
    /** To the customer: the shop cancelled it. */
    case OrderCancelled = 'order_cancelled';
    /** To the customer: the payment failed. */
    case OrderFailed = 'order_failed';
    case OrderRefunded = 'order_refunded';
    /** To the customer: a note from the shop. */
    case CustomerNote = 'customer_note';
    /** To the customer: a new link to the receipt, when theirs expired. */
    case ReceiptLink = 'receipt_link';
    /** To the shop: a new order to look at. */
    case NewOrder = 'new_order';
    /** To the shop: an order needs attention, such as a payment disputed after it was paid. */
    case OrderAttention = 'order_attention';

    public function template(): string
    {
        return 'emails/' . str_replace('_', '-', $this->value) . '.html.twig';
    }

    public function isForShop(): bool
    {
        return $this === self::NewOrder || $this === self::OrderAttention;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The emails the shop can send again from the order alone: those about its status. A note, a refund or what
     * needed attention is told once, with what it was about.
     *
     * @return list<string>
     */
    public static function resendable(): array
    {
        return array_column(array_filter(self::cases(), static fn(self $kind): bool => !\in_array($kind, [self::CustomerNote, self::OrderRefunded, self::OrderAttention, self::ReceiptLink], true)), 'value');
    }
}
