<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Application\OAuthEndpoints;
use OrillaCart\Integrations\Infrastructure\WordPress\Hooks\OAuthConsent;
use OrillaCart\Shared\Http\Routing\RouteCollection;

/**
 * The OAuth server's addresses on this site. Its issuer is the shop's /oauth REST address, so that a client that
 * appends OpenID Connect's discovery path to it reaches the shop's metadata with no rewrite rule. A site without
 * pretty permalinks has no such address (an issuer has no query), and a site served over plain http beyond this
 * machine would send tokens in the clear: neither offers OAuth.
 */
final readonly class WordPressOAuthEndpoints implements OAuthEndpoints
{
    public function issuer(): ?string
    {
        $permalinks = get_option('permalink_structure');
        if (!\is_string($permalinks) || $permalinks === '') {
            return null;
        }
        $issuer = self::rest('oauth');
        $parts = wp_parse_url($issuer);
        if (!\is_array($parts) || isset($parts['query'])) {
            return null;
        }
        $secure = ($parts['scheme'] ?? '') === 'https' || \in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);

        return $secure ? $issuer : null;
    }

    public function resource(): string
    {
        return self::rest('mcp');
    }

    public function authorization(): string
    {
        return admin_url('admin.php?page=' . OAuthConsent::PAGE);
    }

    public function token(): string
    {
        return self::rest('oauth/token');
    }

    public function registration(): string
    {
        return self::rest('oauth/register');
    }

    public function protectedResource(): string
    {
        return self::rest('oauth/protected-resource');
    }

    private static function rest(string $path): string
    {
        return untrailingslashit(rest_url(RouteCollection::REST_NAMESPACE . '/' . $path));
    }
}
