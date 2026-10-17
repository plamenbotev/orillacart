<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Gateway;

/**
 * The customer pays the courier when the order arrives.
 */
final class CashOnDelivery extends OfflineGateway
{
    public function id(): string
    {
        return 'cash_on_delivery';
    }

    public function defaultName(): string
    {
        return 'Cash on delivery';
    }
}
