<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Integrations\Infrastructure\WordPress\ApiKeyRequest;
use OrillaCart\Shared\Http\Security\CsrfProtection;

final readonly class WordPressCsrfProtection implements CsrfProtection
{
    public function __construct(private ApiKeyRequest $apiKey) {}

    public function isRequired(): bool
    {
        // Not when the request authenticated with an application password (WordPress says so), or with one of the
        // shop's API keys (this request's, not any earlier one's), instead of a cookie.
        return did_action('application_password_did_authenticate') === 0 && $this->apiKey->key === null;
    }

    public function isTokenValid(string $token, string $action): bool
    {
        return wp_verify_nonce($token, $action) !== false;
    }

    public function newToken(string $action): string
    {
        return wp_create_nonce($action);
    }
}
