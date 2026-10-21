<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

use OrillaCart\Integrations\Domain\ApiKey;

final readonly class ApiKeyView
{
    public function __construct(
        public int $id,
        public string $name,
        public int $userId,
        public string $user,
        /** read or read_write */
        public string $scope,
        /** The key's first characters, to tell it from the others. */
        public string $prefix,
        public string $createdAt,
        public ?string $lastUsedAt,
    ) {}

    public static function of(ApiKey $key, string $user): self
    {
        return new self($key->id(), $key->name(), $key->userId(), $user, $key->scope()->value, $key->prefix(), $key->createdAt()->format(\DATE_ATOM), $key->lastUsedAt()?->format(\DATE_ATOM));
    }
}
