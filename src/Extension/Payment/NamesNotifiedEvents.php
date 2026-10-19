<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * A gateway whose notifications carry the provider's own event id: the shop handles each event once, so a
 * notification sent again (a provider retrying, or someone replaying one they caught) changes nothing.
 */
interface NamesNotifiedEvents extends HandlesNotifications
{
    /** The provider's id of the event, read without checking it; null when it has none. */
    public function eventId(Notification $notification): ?string;
}
