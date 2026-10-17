<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\CustomerRecord;
use OrillaCart\Ordering\Application\Dto\AddressData;

/**
 * Where customer accounts live: WordPress users with the shop's customer role, and their saved addresses.
 */
interface Accounts
{
    public function find(int $id): ?CustomerRecord;

    public function isRegistered(string $email): bool;

    /**
     * @return int the new account's id
     */
    public function register(string $email, string $password, string $firstName, string $lastName): int;

    /** Logs the browser in as the account, as WordPress's login does. */
    public function logIn(int $id): void;

    public function saveAddresses(int $id, ?AddressData $billing, ?AddressData $shippingAddress): void;

    /**
     * Customers newest first, and how many match in all. The search matches email and names.
     *
     * @return array{list<CustomerRecord>, int}
     */
    public function search(?string $text, int $offset, int $limit): array;
}
