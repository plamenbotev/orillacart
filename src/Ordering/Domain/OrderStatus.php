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
    /** Fulfilled. */
    case Completed = 'completed';
    /** Given up by the customer or the shop; its stock went back. */
    case Cancelled = 'cancelled';
    /** Its payment failed; its stock went back. */
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return $this === self::PendingPayment || $this === self::OnHold;
    }
}
