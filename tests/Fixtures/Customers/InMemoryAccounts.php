<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Customers;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Customers\Application\Dto\CustomerRecord;

final class InMemoryAccounts implements Accounts
{
    /** @var array<int, CustomerRecord> */
    public array $accounts = [];
    /** @var array<int, string> */
    public array $passwords = [];
    public ?int $loggedIn = null;
    private int $nextId = 100;

    public function add(string $email, string $firstName = 'Ada', string $lastName = 'Lovelace'): int
    {
        $id = $this->nextId++;
        $this->accounts[$id] = new CustomerRecord($id, $email, $firstName, $lastName, '2026-10-17T12:00:00+00:00');

        return $id;
    }

    public function find(int $id): ?CustomerRecord
    {
        return $this->accounts[$id] ?? null;
    }

    public function idOf(string $email): ?int
    {
        foreach ($this->accounts as $id => $account) {
            if (strcasecmp($account->email, $email) === 0) {
                return $id;
            }
        }

        return null;
    }

    public function isRegistered(string $email): bool
    {
        foreach ($this->accounts as $account) {
            if (strcasecmp($account->email, $email) === 0) {
                return true;
            }
        }

        return false;
    }

    public function register(string $email, string $password, string $firstName, string $lastName): int
    {
        $id = $this->add($email, $firstName, $lastName);
        $this->passwords[$id] = $password;

        return $id;
    }

    public function logIn(int $id): void
    {
        $this->loggedIn = $id;
    }

    public function saveAddresses(int $id, AddressBook $addresses): void
    {
        $account = $this->accounts[$id];
        $this->accounts[$id] = new CustomerRecord($id, $account->email, $account->firstName, $account->lastName, $account->registeredAt, $addresses, $account->cartReminders);
    }

    public function saveCartReminders(int $id, bool $on): void
    {
        $account = $this->accounts[$id];
        $this->accounts[$id] = new CustomerRecord($id, $account->email, $account->firstName, $account->lastName, $account->registeredAt, $account->addresses, $on);
    }

    public function search(?string $text, int $offset, int $limit): array
    {
        $found = array_values(array_filter($this->accounts, static fn(CustomerRecord $account): bool => $text === null || str_contains($account->email, $text)));

        return [\array_slice(array_reverse($found), $offset, $limit), \count($found)];
    }
}
