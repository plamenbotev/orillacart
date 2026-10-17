<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Gateway;

use OrillaCart\Payment\Application\Dto\GatewayStart;
use OrillaCart\Payment\Application\Dto\PaymentSession;

/**
 * A payment made outside the shop: the order waits until the shop has the money. What the customer has to do
 * is the method's instructions and receipt note.
 */
abstract class OfflineGateway implements PaymentGateway
{
    public function usesNotifications(): bool
    {
        return false;
    }

    public function settings(): array
    {
        return [];
    }

    public function isReady(array $settings, string $currency): bool
    {
        return true;
    }

    public function start(PaymentSession $session, array $settings): GatewayStart
    {
        return GatewayStart::offline();
    }
}
