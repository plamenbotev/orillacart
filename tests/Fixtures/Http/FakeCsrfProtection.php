<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Http;

use OrillaCart\Shared\Http\Security\CsrfProtection;

/**
 * Accepts exactly one token per nonce action.
 */
final class FakeCsrfProtection implements CsrfProtection
{
    /** @param array<string, string> $validTokens action => token */
    public function __construct(
        public array $validTokens = [],
        public bool $required = true,
    ) {}

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isTokenValid(string $token, string $action): bool
    {
        return ($this->validTokens[$action] ?? null) === $token;
    }

    public function newToken(string $action): string
    {
        return $this->validTokens[$action] ??= bin2hex(random_bytes(5));
    }
}
