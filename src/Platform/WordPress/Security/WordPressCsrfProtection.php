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

    public function isTokenValid(#[\SensitiveParameter] string $token, string $action): bool
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
        return \in_array(self::origin($origin), self::origins(home_url(), site_url(), get_allowed_http_origins()), true);
    }

    /**
     * The origins a browser's request to this site may name: the site's own, and what the site added through
     * WordPress's allowed_http_origins. WordPress's own list leaves the port out, so a site on a port
     * (localhost:8888, a host's :8443) would be foreign to itself, and the same host on another port would not be.
     * Without a port both schemes of the host count, as in WordPress; with one, only the address as the site has it.
     *
     * @param list<string>|array<array-key, mixed> $listed WordPress's allowed origins, as filtered
     *
     * @return list<string>
     */
    public static function origins(string $home, string $site, array $listed): array
    {
        $own = [];
        $portless = [];
        foreach ([$home, $site] as $url) {
            $parts = parse_url($url);
            $host = \is_array($parts) && \is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
            if ($host === '') {
                continue;
            }
            // What WordPress lists for the host by itself: ours only when the site has no port.
            $portless[] = 'http://' . $host;
            $portless[] = 'https://' . $host;
            $exact = self::origin(($parts['scheme'] ?? 'http') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            if ($exact === 'http://' . $host || $exact === 'https://' . $host) {
                $own[] = 'http://' . $host;
                $own[] = 'https://' . $host;
            } else {
                $own[] = $exact;
            }
        }
        foreach ($listed as $origin) {
            // Not WordPress's own entry for the site's host: one the site added.
            if (\is_string($origin) && !\in_array(self::origin($origin), $portless, true)) {
                $own[] = self::origin($origin);
            }
        }

        return array_values(array_unique($own));
    }

    /** An origin as origins are compared: lower case, without the scheme's own port or a trailing slash. */
    public static function origin(string $origin): string
    {
        $origin = strtolower(rtrim(trim($origin), '/'));

        return preg_replace(['~^(http://[^/]+):80$~', '~^(https://[^/]+):443$~'], '$1', $origin) ?? $origin;
    }
}
