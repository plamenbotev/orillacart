<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

use OrillaCart\Integrations\Domain\ApiKeyScope;

/** Who a request with a valid key acts as, and what it may do. */
final readonly class AuthenticatedKey
{
    /**
     * @param array<array-key, mixed>|null $assistant what the owner chose for the AI assistant that uses the key
     */
    public function __construct(
        public int $keyId,
        public int $userId,
        public ApiKeyScope $scope,
        public string $name = '',
        public ?array $assistant = null,
    ) {}
}
