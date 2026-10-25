<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Domain;

use OrillaCart\Shared\Domain\NotFound;

/** The same whether the number is no order's or the email is not the order's: it gives away neither. */
final class WithdrawalOrderNotFound extends NotFound
{
    public function __construct()
    {
        parent::__construct('No order has this number and email.', 'orillacart_withdrawal_order_not_found');
    }
}
