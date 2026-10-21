<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

interface ApiKeyRepository
{
    /** @return list<ApiKey> newest first */
    public function all(): array;

    public function find(int $id): ?ApiKey;

    public function withHash(string $hash): ?ApiKey;

    public function save(ApiKey $key): void;

    public function remove(ApiKey $key): void;
}
