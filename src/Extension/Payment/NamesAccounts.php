<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

use OrillaCart\Extension\Settings;

/**
 * A gateway whose settings can point at different accounts or modes of the provider, such as a sandbox and live:
 * each payment keeps the one it was made in, and the shop asks about it, and refunds it, only while the settings
 * still point there. Switching the mode then never reads the old payments as unknown, and so cancels nothing.
 * Since version 11.
 */
interface NamesAccounts extends PaymentGateway
{
    /** Which account or mode the settings point at, such as "sandbox:<client id>"; up to 100 characters. */
    public function account(Settings $settings): string;
}
