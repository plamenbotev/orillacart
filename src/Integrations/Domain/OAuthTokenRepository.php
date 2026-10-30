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

    /** Forgets the tokens whose refresh ran out before then. */
    public function removeExpired(\DateTimeImmutable $before): void;
}
