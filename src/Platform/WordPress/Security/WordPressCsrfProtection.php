<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Http\Security\CsrfProtection;

final readonly class WordPressCsrfProtection implements CsrfProtection
{
    public function isRequired(): bool
    {
        // Set by WordPress when the request authenticated with an application password instead of a cookie.
        return did_action('application_password_did_authenticate') === 0;
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
