<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class PaymentMethodNotFound extends NotFound
{
    public function __construct(string $gateway)
    {
        parent::__construct(\sprintf('There is no payment method "%s".', $gateway), 'orillacart_payment_method_not_found');
    }
}
