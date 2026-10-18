<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;

/**
 * A gateway whose provider calls the shop back (webhooks), so payments settle even when the customer never
 * comes back. The admin screen shows the address to give the provider.
 */
interface HandlesNotifications
{
    /**
     * Checks that the provider sent the notification, then says what it means for which payment. The shop
     * settles each payment once; repeated notifications change nothing.
     *
     * @return PaymentOutcome|null null for notifications about anything else
     *
     * @throws InvalidNotification when the provider did not send it
     * @throws ProviderUnavailable when the provider could not be asked to confirm it; it will send it again
     */
    public function notified(Notification $notification, Settings $settings): ?PaymentOutcome;
}
