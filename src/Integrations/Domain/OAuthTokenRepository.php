<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

interface OAuthTokenRepository
{
    public function withAccessHash(string $hash): ?OAuthToken;

    /**
     * The token whose refresh token has this hash, or whose last rotation replaced it, locked against another
     * refresh until the transaction ends: two refreshes of one token run one after the other. Inside a transaction.
     */
    public function forRefresh(string $hash): ?OAuthToken;

    public function save(OAuthToken $token): void;

    public function remove(OAuthToken $token): void;

    /** Every token the app holds for the key: when a code it was given comes back a second time. */
    public function removeFor(int $keyId, string $clientId): void;

    /** Forgets the tokens whose refresh ran out before then. */
    public function removeExpired(\DateTimeImmutable $before): void;
}
