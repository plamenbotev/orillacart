<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

/**
 * An OAuth request refused as RFC 6749 words it: an error code ("invalid_grant"), what was wrong, and the HTTP
 * status. One about the client or its redirect address is never sent back to that address.
 */
final class OAuthError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        public readonly bool $redirectable = false,
        /** Where to send a redirectable error: an address the client registered. */
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($description);
    }

    /** @return array{error: string, error_description: string} */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
