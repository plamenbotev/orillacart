<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

use OrillaCart\Extension\Settings;

/**
 * A gateway whose customers come back from the provider. The shop then shows them their receipt.
 */
interface HandlesReturns
{
    /**
     * Which payment the return is about: its reference, as PaymentStart::redirect() gave it; null when the
     * return names none.
     */
    public function reference(PaymentReturn $return): ?string;

    /**
     * How the payment stands now. The shop asks only while the payment is still open, so a customer coming back
     * twice does not reach the provider twice. A gateway that cannot tell yet answers PaymentOutcome::pending()
     * and leaves it to a notification.
     */
    public function returned(string $reference, PaymentReturn $return, Settings $settings): PaymentOutcome;
}
