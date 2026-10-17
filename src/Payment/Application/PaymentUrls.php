<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

/**
 * The shop's addresses a payment provider sends customers and notifications to.
 */
interface PaymentUrls
{
    /** Where the customer comes back after approving the payment. */
    public function returnUrl(string $gateway): string;

    /** Where the customer comes back after giving up at the provider. */
    public function cancelUrl(string $gateway): string;

    /** Where the provider sends its notifications (webhooks). */
    public function notificationUrl(string $gateway): string;
}
