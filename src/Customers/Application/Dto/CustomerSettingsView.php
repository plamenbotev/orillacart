<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

final readonly class CustomerSettingsView
{
    /**
     * @param list<string>                            $modes
     * @param list<string>                            $roles          the roles whose users are the shop's customers
     * @param list<array{name: string, label: string}> $availableRoles the site's roles that can be customers' roles
     */
    public function __construct(
        public string $accounts,
        public array $modes,
        /** A new account takes the orders placed with its email as a guest before. */
        public bool $guestOrders = true,
        public array $roles = [],
        public array $availableRoles = [],
    ) {}
}
