<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

interface OAuthTokenRepository
{
    public function withAccessHash(string $hash): ?OAuthToken;

    public function withRefreshHash(string $hash): ?OAuthToken;

    /** The token whose last rotation replaced the refresh token with this hash. */
    public function withPreviousRefreshHash(string $hash): ?OAuthToken;

    public function save(OAuthToken $token): void;

    public function remove(OAuthToken $token): void;

    /** Forgets the tokens whose refresh ran out before then. */
    public function removeExpired(\DateTimeImmutable $before): void;
}
