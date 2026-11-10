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
    /** @var array<int, list<int>> */
    public array $wishlists = [];
    /** @var array<int, CustomerRecord> users of another shop of the network, by id */
    public array $elsewhere = [];
    /** @var array<int, true> */
    public array $tests = [];
    private int $nextId = 100;

    public function add(string $email, string $firstName = 'Ada', string $lastName = 'Lovelace'): int
    {
        $id = $this->nextId++;
        $this->accounts[$id] = new CustomerRecord($id, $email, $firstName, $lastName, '2026-10-17T12:00:00+00:00');

        return $id;
    }

    public function find(int $id): ?CustomerRecord
    {
        $record = $this->accounts[$id] ?? null;

        return $record === null ? null : new CustomerRecord($record->id, $record->email, $record->firstName, $record->lastName, $record->registeredAt, $record->addresses, $record->cartReminders, $record->locale, isset($this->tests[$id]));
    }

    /** @var list<int> staff, who are not customers */
    public array $staff = [];

    public function isShopCustomer(int $id): bool
    {
        return isset($this->accounts[$id]) && !\in_array($id, $this->staff, true);
    }

    /** @var array<string, string> */
    public array $roles = ['orillacart_customer' => 'Shop customer', 'subscriber' => 'Subscriber', 'author' => 'Author'];

    public function availableRoles(): array
    {
        return $this->roles;
    }

    public function markTest(int $id): void
    {
        $this->tests[$id] = true;
    }

    public function isTestEmail(string $email): bool
    {
        $id = $this->idOf($email);

        return $id !== null && isset($this->tests[$id]);
    }

    public function testIds(): array
    {
        return array_keys($this->tests);
    }

    public function remove(int $id): void
    {
        unset($this->accounts[$id], $this->tests[$id]);
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

    /** Whether the shop is one of a network's: so when users of other shops are given. */
    public bool $network = false;

    public function onNetwork(): bool
    {
        return $this->network || $this->elsewhere !== [];
    }

    public function elsewhere(string $email): ?int
    {
        foreach ($this->elsewhere as $id => $account) {
            if (strcasecmp($account->email, $email) === 0) {
                return $id;
            }
        }

        return null;
    }

    public function join(int $id): void
    {
        $this->accounts[$id] = $this->elsewhere[$id] ?? throw new \LogicException("User {$id} is no other shop's.");
        unset($this->elsewhere[$id]);
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
        $this->accounts[$id] = new CustomerRecord($id, $account->email, $account->firstName, $account->lastName, $account->registeredAt, $addresses, $account->cartReminders, $account->locale);
    }

    public function saveCartReminders(int $id, bool $on): void
    {
        $account = $this->accounts[$id];
        $this->accounts[$id] = new CustomerRecord($id, $account->email, $account->firstName, $account->lastName, $account->registeredAt, $account->addresses, $on, $account->locale);
    }

    public function wishlist(int $id): array
    {
        return $this->wishlists[$id] ?? [];
    }

    public function saveWishlist(int $id, array $productIds): void
    {
        $this->wishlists[$id] = $productIds;
    }

    public function search(?string $text, int $offset, int $limit): array
    {
        $found = array_values(array_filter($this->accounts, static fn(CustomerRecord $account): bool => $text === null || str_contains($account->email, $text)));

        return [\array_slice(array_reverse($found), $offset, $limit), \count($found)];
    }
}
