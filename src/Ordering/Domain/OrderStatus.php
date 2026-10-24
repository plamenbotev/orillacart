<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Text;

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

    /** Placed, and the shop waits for the payment before fulfilling it: the stock is held for it. */
    public function isOpen(): bool
    {
        return $this === self::PendingPayment || $this === self::OnHold;
    }

    /** Being fulfilled or fulfilled. Whether it is paid is another matter: see Order::isPaid(). */
    public function isFulfilling(): bool
    {
        return \in_array($this, [self::Processing, self::Shipped, self::Completed], true);
    }

    /** Over: cancelled, failed or refunded. */
    public function isClosed(): bool
    {
        return \in_array($this, [self::Cancelled, self::Failed, self::Refunded], true);
    }

    /**
     * The statuses the shop may move an order to from this one, whatever its payment. Cancelled, failed and
     * refunded orders are final. Order::nextStatuses() adds cancelling an unpaid order being fulfilled, such as
     * cash on delivery the customer did not take.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::PendingPayment => [self::OnHold, self::Processing, self::Cancelled],
            self::OnHold => [self::Processing, self::Cancelled],
            self::Processing => [self::Shipped, self::Completed],
            self::Shipped => [self::Completed],
            self::Completed => [],
            self::Cancelled, self::Failed, self::Refunded => [],
        };
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /** The status inside a sentence, "on hold", to be translated with it. */
    public function phrase(): Text
    {
        return new Text(match ($this) {
            self::PendingPayment => 'pending payment',
            self::OnHold => 'on hold',
            self::Processing => 'processing',
            self::Shipped => 'shipped',
            self::Completed => 'completed',
            self::Cancelled => 'cancelled',
            self::Failed => 'failed',
            self::Refunded => 'refunded',
        });
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
