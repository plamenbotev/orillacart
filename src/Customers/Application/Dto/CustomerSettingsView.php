<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

final readonly class CustomerSettingsView
{
    /**
     * @param list<string> $modes
     */
    public function __construct(
        public string $accounts,
        public array $modes,
        /** A new account takes the orders placed with its email as a guest before. */
        public bool $guestOrders = true,
    ) {}
}
