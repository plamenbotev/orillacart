<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Integrations\Infrastructure\WordPress\ApiKeyRequest;
use OrillaCart\Shared\Http\Security\CsrfProtection;

/**
 * WordPress's nonces. A visitor's nonce is made for this site: WordPress's would be the same on every site of a
 * network (one salt, no user), and the sites of a subdirectory network share an origin, so a sibling site's page
 * could fetch one and send it here.
 */
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
        return wp_verify_nonce($token, self::action($action)) !== false;
    }

    public function newToken(string $action): string
    {
        return wp_create_nonce(self::action($action));
    }

    /** The nonce's action as the shop makes it: with the site's id for a visitor; a user's nonce is theirs already. */
    public static function action(string $action): string
    {
        return is_user_logged_in() ? $action : $action . '|site-' . get_current_blog_id();
    }

    public function allowsOrigin(string $origin): bool
    {
        return is_allowed_http_origin($origin) !== '';
    }
}
