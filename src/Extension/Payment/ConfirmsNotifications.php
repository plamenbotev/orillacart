<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * A gateway whose notifications the shop does not take at their word, as a scripted one's: its notification
 * address carries a key of the shop's (NOTIFICATION_KEY), which the shop checks before the gateway reads
 * anything, and a payment a notification says is completed is asked about first (ChecksPayments::check()),
 * under the shop's own reference; only what the provider answers settles it.
 */
interface ConfirmsNotifications extends HandlesNotifications, ChecksPayments
{
    /** The query parameter of the notification address that carries the shop's key. */
    public const string NOTIFICATION_KEY = 'key';
}
