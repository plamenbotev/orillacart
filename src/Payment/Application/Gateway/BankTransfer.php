<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Gateway;

/**
 * The customer transfers the money to the shop's bank account.
 */
final class BankTransfer extends OfflineGateway
{
    public function id(): string
    {
        return 'bank_transfer';
    }

    public function defaultName(): string
    {
        return 'Bank transfer';
    }
}
