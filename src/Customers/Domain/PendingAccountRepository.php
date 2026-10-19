<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

interface PendingAccountRepository
{
    public function findByToken(string $token): ?PendingAccount;

    public function findByEmail(string $email): ?PendingAccount;

    public function save(PendingAccount $account): void;

    public function remove(PendingAccount $account): void;

    /** Forgets the accounts whose links stopped working before then. */
    public function removeExpired(\DateTimeImmutable $now): void;
}
