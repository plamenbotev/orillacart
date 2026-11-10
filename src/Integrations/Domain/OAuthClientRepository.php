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

    /** How many clients the owner never connected there are. */
    public function countUnconnected(): int;

    /**
     * Forgets the oldest clients the owner never connected, of those registered before then.
     *
     * @return int how many went
     */
    public function removeOldestUnconnected(int $count, \DateTimeImmutable $before): int;
}
