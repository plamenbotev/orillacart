<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Gateway;

use OrillaCart\Payment\Application\Dto\GatewaySetting;
use OrillaCart\Payment\Application\Dto\GatewayStart;
use OrillaCart\Payment\Application\Dto\PaymentSession;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;

/**
 * A way to pay, such as cash on delivery or PayPal. The shop turns it on and names it as a payment method;
 * the gateway starts payments for orders. Every implementation is found through the TAG (config/services.php).
 */
interface PaymentGateway
{
    public const string TAG = 'orillacart.payment_gateway';

    /** Stable id, stored with payment methods and payments: "cash_on_delivery". */
    public function id(): string;

    /** The name checkout shows until the shop renames the method. */
    public function defaultName(): string;

    /** Whether the provider sends notifications (webhooks) the shop must be subscribed to. */
    public function usesNotifications(): bool;

    /** @return list<GatewaySetting> */
    public function settings(): array;

    /**
     * Whether the gateway can take payments with these settings in this currency; checkout offers only
     * methods that can.
     *
     * @param array<string, string|bool> $settings
     */
    public function isReady(array $settings, string $currency): bool;

    /**
     * @param array<string, string|bool> $settings
     *
     * @throws PaymentProviderUnavailable
     */
    public function start(PaymentSession $session, array $settings): GatewayStart;
}
