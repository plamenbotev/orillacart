<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * A gateway whose notifications make it act at the provider, such as capturing a payment the customer
 * approved, names the payment a notification is about before it is handled. The shop hands the notification
 * on only for a payment it started, so a provider account shared with another site (a staging copy, say) never
 * takes that site's money here. Since version 3.
 */
interface NamesNotifiedPayments extends HandlesNotifications
{
    /**
     * The reference of the payment the notification is about, as start() gave it, read from the notification
     * without checking it (notified() checks it). Null when it names no payment the shop started, such as a
     * refund made at the provider's site: the notification is then handed on as usual.
     */
    public function paymentReference(Notification $notification): ?string;
}
