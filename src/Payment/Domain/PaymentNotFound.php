<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class PaymentNotFound extends NotFound
{
    public function __construct()
    {
        parent::__construct('There is no such payment.', 'orillacart_payment_not_found');
    }
}
