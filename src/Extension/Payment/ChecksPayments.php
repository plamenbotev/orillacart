<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;

/**
 * A gateway that can ask its provider how a payment stands. The shop asks, every hour, about payments it heard
 * nothing final of: ones the provider holds for review, and ones whose customer never came back while no
 * notification came. A gateway that takes the money when the customer approves (a capture) does so here as it
 * does on its notification. Since version 3.
 */
interface ChecksPayments extends PaymentGateway
{
    /**
     * @param string      $reference     the payment's reference, as PaymentStart gave it
     * @param string|null $transactionId the provider's id of the money moved, when an outcome gave one
     *
     * @throws ProviderUnavailable
     */
    public function check(string $reference, ?string $transactionId, Settings $settings): PaymentOutcome;
}
