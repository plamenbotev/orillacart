<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Integrations\Application\OAuthEndpoints;
use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The well-known addresses at the site's root that OAuth clients try first (RFC 8414 and RFC 9728, with the
 * issuer's or resource's path after them): the OAuth server's metadata and the MCP endpoint's resource metadata.
 * They reach WordPress as any unknown address does, and are answered before it looks for a page.
 */
#[AsHook('parse_request', method: 'serve', priority: 0)]
final readonly class OAuthWellKnown
{
    public function __construct(
        private OAuthServer $server,
        private OAuthEndpoints $endpoints,
    ) {}

    public function serve(): void
    {
        $issuer = $this->endpoints->issuer();
        if ($issuer === null) {
            return;
        }
        $path = wp_parse_url(\is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '', \PHP_URL_PATH);
        if (!\is_string($path) || !str_starts_with($path, '/.well-known/')) {
            return;
        }
        $issuerPath = (string) wp_parse_url($issuer, \PHP_URL_PATH);
        $resourcePath = (string) wp_parse_url($this->endpoints->resource(), \PHP_URL_PATH);
        $document = match ($path) {
            '/.well-known/oauth-authorization-server' . $issuerPath, '/.well-known/openid-configuration' . $issuerPath => $this->server->metadata(),
            '/.well-known/oauth-protected-resource' . $resourcePath => $this->server->protectedResource(),
            default => null,
        };
        if ($document === null) {
            return;
        }
        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode($document, \JSON_UNESCAPED_SLASHES);
        exit;
    }
}
