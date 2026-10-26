<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

interface OAuthClientRepository
{
    public function find(string $id): ?OAuthClient;

    public function save(OAuthClient $client): void;
}
