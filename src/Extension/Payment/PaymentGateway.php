<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Settings;

/**
 * A way to pay, such as bank transfer or a card provider. The shop turns it on and names it as a payment
 * method; the gateway starts payments for orders. A gateway that sends customers to a provider implements
 * HandlesReturns, one whose provider calls the shop back implements HandlesNotifications.
 */
interface PaymentGateway
{
    /** Stable, unique id, stored with payment methods and payments: "cash_on_delivery". */
    public function id(): string;

    /** The name checkout shows until the shop renames the method. */
    public function defaultName(): string;

    /** @return list<Setting> */
    public function settings(): array;

    /**
     * Why the gateway cannot take payments with these settings, in this currency with amounts of these decimals;
     * null when it can. Checkout offers only gateways that can, and the shop's admin shows the reason.
     */
    public function notReady(Settings $settings, string $currency, int $decimals): ?string;

    /**
     * Starts paying: offline (the order waits until the shop has the money), or at a provider the customer is
     * sent to, with the provider's id for the payment, which later identifies it.
     *
     * @throws ProviderUnavailable
     */
    public function start(PaymentSession $session, Settings $settings): PaymentStart;
}
