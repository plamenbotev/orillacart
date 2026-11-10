<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * A gateway whose notifications make it act at the provider, such as capturing a payment the customer
 * approved, names the payment a notification is about before it is handled. The shop hands the notification
 * on only for a payment it started and still waits for: a provider account shared with another site (a staging
 * copy, say) never takes that site's money here, and a customer who approves a payment after giving it up, or
 * after it failed, is not charged for it. Since version 3.
 */
interface NamesNotifiedPayments extends HandlesNotifications
{
    /**
     * The reference of the payment the notification is about, as start() gave it, read from the notification
     * without checking it (notified() checks it). Null when the notification makes the gateway take no money:
     * one that tells of money already moved, or of a refund made at the provider's site, is handed on as usual.
     */
    public function paymentReference(Notification $notification): ?string;
}
