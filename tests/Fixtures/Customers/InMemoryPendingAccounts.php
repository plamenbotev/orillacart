<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Customers;

use OrillaCart\Customers\Domain\PendingAccount;
use OrillaCart\Customers\Domain\PendingAccountRepository;

final class InMemoryPendingAccounts implements PendingAccountRepository
{
    /** @var list<PendingAccount> */
    public array $accounts = [];
    /** @var array<int, string> the token hash of each, by object id */
    private array $hashes = [];

    public function findByToken(string $token): ?PendingAccount
    {
        foreach ($this->accounts as $account) {
            if (($this->hashes[spl_object_id($account)] ?? null) === PendingAccount::hash($token)) {
                return $account;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?PendingAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->email() === mb_strtolower(trim($email))) {
                return $account;
            }
        }

        return null;
    }

    public function find(int $id): ?PendingAccount
    {
        return array_find($this->accounts, static fn(PendingAccount $account): bool => $account->id() === $id);
    }

    public function invitations(): array
    {
        $invitations = array_values(array_filter($this->accounts, static fn(PendingAccount $account): bool => $account->isInvitation()));
        usort($invitations, static fn(PendingAccount $a, PendingAccount $b): int => [$b->sentAt(), $b->id()] <=> [$a->sentAt(), $a->id()]);

        return $invitations;
    }

    public function save(PendingAccount $account): void
    {
        if (!\in_array($account, $this->accounts, true)) {
            $this->accounts[] = $account;
            // An id, as the database gives one.
            (function (int $id): void {
                $this->id ??= $id;
            })->call($account, \count($this->hashes) + 1);
        }
        $this->hashes[spl_object_id($account)] = (fn(): string => $this->tokenHash)->call($account);
    }

    public function remove(PendingAccount $account): void
    {
        $this->accounts = array_values(array_filter($this->accounts, static fn(PendingAccount $other): bool => $other !== $account));
    }

    public function removeExpired(\DateTimeImmutable $now): void
    {
        $kept = $now->modify('-30 days');
        $this->accounts = array_values(array_filter($this->accounts, static fn(PendingAccount $account): bool => !$account->isExpired($now) || $account->isInvitation() && !$account->isExpired($kept)));
    }
}
