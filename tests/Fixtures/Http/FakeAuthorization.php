<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Http;

use OrillaCart\Shared\Http\Security\Authorization;

final class FakeAuthorization implements Authorization
{
    /** @param list<string> $capabilities */
    public function __construct(
        public bool $loggedIn = false,
        public array $capabilities = [],
        public ?int $userId = null,
    ) {}

    public function userId(): ?int
    {
        return $this->loggedIn ? $this->userId : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    public function isGranted(string $capability): bool
    {
        return \in_array($capability, $this->capabilities, true);
    }
}
