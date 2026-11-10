<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

final readonly class CustomerSettings
{
    public const int MIN_PASSWORD_LENGTH = 8;
    /** The role the shop gives the accounts it makes; always one of the customers' roles. */
    public const string CUSTOMER_ROLE = 'orillacart_customer';
    /** The customers' roles until the shop chooses: its own, and the one WordPress gives whoever registers. */
    public const array DEFAULT_ROLES = [self::CUSTOMER_ROLE, 'subscriber'];

    /** @var list<string> */
    public array $roles;

    /**
     * @param bool              $guestOrders a new account takes the orders placed with its email as a guest before
     *                                       (its owner proved the address by following the emailed link); off, only
     *                                       those placed while asking for it
     * @param list<string>|null $roles       the roles whose users are the shop's customers: a user is one when every
     *                                       role they have is listed, so nobody the site gave another job to (an
     *                                       editor, another plugin's staff) is shown or changed as a customer
     */
    public function __construct(
        public AccountMode $accounts = AccountMode::Optional,
        public bool $guestOrders = true,
        ?array $roles = null,
    ) {
        $this->roles = array_values(array_unique([self::CUSTOMER_ROLE, ...($roles ?? self::DEFAULT_ROLES)]));
    }

    /**
     * Whether a user of these roles is a customer: they have a role, and every one of them is a customers' role.
     *
     * @param list<string> $userRoles
     * @param list<string> $customerRoles
     */
    public static function areCustomers(array $userRoles, array $customerRoles): bool
    {
        return $userRoles !== [] && array_diff($userRoles, $customerRoles) === [];
    }
}
