<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

interface OAuthClientRepository
{
    public function find(string $id): ?OAuthClient;

    public function save(OAuthClient $client): void;

    /**
     * Forgets the clients registered before then that the owner never connected (no key names them).
     *
     * @return int how many went
     */
    public function removeUnconnected(\DateTimeImmutable $before): int;
}
