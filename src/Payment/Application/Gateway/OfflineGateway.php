<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Gateway;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Settings;

/**
 * A payment made outside the shop: the order waits until the shop has the money. What the customer has to do
 * is the method's instructions and receipt note.
 */
abstract class OfflineGateway implements PaymentGateway
{
    public function settings(): array
    {
        return [];
    }

    public function notReady(Settings $settings, string $currency, int $decimals): ?string
    {
        return null;
    }

    public function start(PaymentSession $session, Settings $settings): PaymentStart
    {
        return PaymentStart::offline();
    }

    /** Whether the customer pays before the order ships, so an order waits for them; not so cash on delivery. */
    public function paidFirst(): bool
    {
        return true;
    }
}
