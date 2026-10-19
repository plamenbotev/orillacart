<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

/**
 * The shop's addresses a payment provider sends customers and notifications to.
 */
interface PaymentUrls
{
    /** Where the customer comes back after approving the payment: signed with its id, see PaymentOutcomes::returned(). */
    public function returnUrl(string $gateway, int $paymentId): string;

    /** Where the customer comes back after giving up at the provider, signed alike. */
    public function cancelUrl(string $gateway, int $paymentId): string;

    /** Where the provider sends its notifications (webhooks). */
    public function notificationUrl(string $gateway): string;
}
