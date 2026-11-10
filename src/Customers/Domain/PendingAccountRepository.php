<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

interface PendingAccountRepository
{
    public function findByToken(#[\SensitiveParameter] string $token): ?PendingAccount;

    public function findByEmail(string $email): ?PendingAccount;

    public function find(int $id): ?PendingAccount;

    /** @return list<PendingAccount> the shop's invitations, the newest first */
    public function invitations(): array;

    public function save(PendingAccount $account): void;

    public function remove(PendingAccount $account): void;

    /**
     * Forgets the sign-ups whose links stopped working, and the invitations a month after theirs did: until then
     * the shop sees them, and may send them again.
     */
    public function removeExpired(\DateTimeImmutable $now): void;
}
