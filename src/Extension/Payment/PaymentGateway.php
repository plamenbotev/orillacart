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

    /** Whether the gateway can take payments with these settings in this currency; checkout offers only those. */
    public function isReady(Settings $settings, string $currency): bool;

    /**
     * Starts paying: offline (the order waits until the shop has the money), or at a provider the customer is
     * sent to, with the provider's id for the payment, which later identifies it.
     *
     * @throws ProviderUnavailable
     */
    public function start(PaymentSession $session, Settings $settings): PaymentStart;
}
