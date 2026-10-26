<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

/**
 * An OAuth authorization request the shop checked: from a client it knows, back to an address that client
 * registered, with a PKCE challenge.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public OAuthClientView $client,
        public string $redirectUri,
        public string $codeChallenge,
        public ?string $state,
        public bool $write,
    ) {}
}
