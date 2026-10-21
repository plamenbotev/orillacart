<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Http\Security\CsrfProtection;

final readonly class WordPressCsrfProtection implements CsrfProtection
{
    public function isRequired(): bool
    {
        // Set when the request authenticated with an application password, or one of the shop's API keys, instead
        // of a cookie.
        return did_action('application_password_did_authenticate') === 0 && did_action('orillacart_api_key_did_authenticate') === 0;
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
