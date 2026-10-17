<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

enum OrderStatus: string
{
    /** Placed; the customer is paying at a provider. Stock is taken. */
    case PendingPayment = 'pending_payment';
    /** Placed; the shop waits for an offline payment or a provider's review. Stock is taken. */
    case OnHold = 'on_hold';
    /** Paid, or free: the shop fulfils it. */
    case Processing = 'processing';
    /** On its way to the customer. */
    case Shipped = 'shipped';
    /** Fulfilled. */
    case Completed = 'completed';
    /** Given up by the customer or the shop; its stock went back. */
    case Cancelled = 'cancelled';
    /** Its payment failed; its stock went back. */
    case Failed = 'failed';
    /** The shop gave the money back. */
    case Refunded = 'refunded';

    /** Placed but not paid: the stock is held for it. */
    public function isOpen(): bool
    {
        return $this === self::PendingPayment || $this === self::OnHold;
    }

    /** Paid (or free) and not refunded: what the customer bought is theirs, downloads included. */
    public function isPaid(): bool
    {
        return \in_array($this, [self::Processing, self::Shipped, self::Completed], true);
    }

    /**
     * The statuses the shop may move an order to from this one. Cancelled, failed and refunded orders are final.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::PendingPayment => [self::OnHold, self::Processing, self::Cancelled],
            self::OnHold => [self::Processing, self::Cancelled],
            self::Processing => [self::Shipped, self::Completed, self::Cancelled, self::Refunded],
            self::Shipped => [self::Completed, self::Refunded],
            self::Completed => [self::Refunded],
            self::Cancelled, self::Failed, self::Refunded => [],
        };
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
