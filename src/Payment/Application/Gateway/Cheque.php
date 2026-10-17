<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Gateway;

/**
 * The customer sends a cheque.
 */
final class Cheque extends OfflineGateway
{
    public function id(): string
    {
        return 'cheque';
    }

    public function defaultName(): string
    {
        return 'Cheque';
    }
}
