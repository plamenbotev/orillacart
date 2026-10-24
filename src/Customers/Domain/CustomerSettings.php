<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

final readonly class CustomerSettings
{
    public const int MIN_PASSWORD_LENGTH = 8;

    /**
     * @param bool $guestOrders a new account takes the orders placed with its email as a guest before (its owner
     *                          proved the address by following the emailed link); off, only those placed while
     *                          asking for it
     */
    public function __construct(
        public AccountMode $accounts = AccountMode::Optional,
        public bool $guestOrders = true,
    ) {}
}
