<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

/**
 * The emails the shop can turn off, each from its template in templates/emails/: those about orders, and the
 * welcome to a new account.
 */
enum EmailKind: string
{
    /** To the customer: the order is placed and waits for their offline payment. */
    case OrderReceived = 'order_received';
    /** To the customer: paid (or free), the shop is preparing it. */
    case OrderProcessing = 'order_processing';
    /** To the customer, with the order's first email: some items are owed, and come later. */
    case OrderBackordered = 'order_backordered';
    /** To the customer: a courier has the shipment booked, and it can be followed. */
    case TrackingAdded = 'tracking_added';
    case OrderShipped = 'order_shipped';
    case OrderCompleted = 'order_completed';
    /** To the customer: the order still waits for their offline payment, some days after it was placed. */
    case PaymentReminder = 'payment_reminder';
    /** To the customer: the shop cancelled it. */
    case OrderCancelled = 'order_cancelled';
    /** To the customer: the payment failed. */
    case OrderFailed = 'order_failed';
    case OrderRefunded = 'order_refunded';
    /** To the customer: a note from the shop. */
    case CustomerNote = 'customer_note';
    /** To the customer: a new link to the receipt, when theirs expired. */
    case ReceiptLink = 'receipt_link';
    /** To a visitor who asked: a sold-out product or variation is back in stock. */
    case BackInStock = 'back_in_stock';
    /** To the customer: their account is made. */
    case AccountWelcome = 'account_welcome';
    /** To the shop: a new order to look at. */
    case NewOrder = 'new_order';
    /** To the shop: an order needs attention, such as a payment disputed after it was paid. */
    case OrderAttention = 'order_attention';
    /** To the shop: an order's payment failed. */
    case PaymentFailed = 'payment_failed';
    /** To the shop: money went back for an order. */
    case RefundMade = 'refund_made';
    /** To the shop, daily when an item ran low since: what the stockrooms hold few of. */
    case LowStock = 'low_stock';

    public function template(): string
    {
        return 'emails/' . str_replace('_', '-', $this->value) . '.html.twig';
    }

    public function isForShop(): bool
    {
        return \in_array($this, [self::NewOrder, self::OrderAttention, self::PaymentFailed, self::RefundMade, self::LowStock], true);
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
        return array_column(array_filter(self::cases(), static fn(self $kind): bool => !\in_array($kind, [self::CustomerNote, self::OrderRefunded, self::RefundMade, self::OrderAttention, self::ReceiptLink, self::AccountWelcome, self::LowStock, self::BackInStock], true)), 'value');
    }
}
