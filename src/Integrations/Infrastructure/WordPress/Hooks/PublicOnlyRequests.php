<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Shared\WordPress\OutboundRequests;
use WpOrg\Requests\Transport\Curl;

/**
 * For a request sent through OutboundRequests: the host is resolved just before it connects and refused unless
 * every address is public, and the connection is pinned to those addresses (CURLOPT_RESOLVE, for each port curl may
 * take), so DNS cannot say one thing to the check and another to the connection. Last of the filters: a request a
 * test or another plugin answers itself connects nowhere and is not second-guessed. The pin needs curl to connect
 * itself, so a request WordPress would send without curl, or through a proxy, is refused; so is one started through
 * OutboundRequests whose guard argument a filter took away.
 */
#[AsHook('pre_http_request', method: 'check', filter: true, priority: \PHP_INT_MAX, arguments: 3, anySite: true)]
#[AsHook('http_api_curl', method: 'pin', priority: 10, arguments: 3, anySite: true)]
final class PublicOnlyRequests
{
    /** @var array<string, list<string>> url → the addresses it was checked at, until it connects */
    private array $pinned = [];

    public function check(mixed $pre, mixed $args, mixed $url): mixed
    {
        if ($pre !== false || !\is_array($args) || !\is_string($url)) {
            return $pre;
        }
        $guarded = self::guarded($args, $url);
        if ($guarded === null) {
            return new \WP_Error('orillacart_unsafe_address', \sprintf('The request to %s lost the shop\'s address check on the way: a filter took it away.', $url));
        }
        if (!$guarded) {
            return $pre;
        }
        if (!class_exists(Curl::class) || !Curl::test(['ssl' => parse_url($url, \PHP_URL_SCHEME) === 'https'])) {
            return new \WP_Error('orillacart_unsafe_address', 'The shop calls addresses others chose only through curl, which it can hold to the address it checked; this server sends WordPress\'s requests another way.');
        }
        $proxy = new \WP_HTTP_Proxy();
        // A site whose only way out is its proxy says the proxy holds requests to public addresses itself.
        if ($proxy->is_enabled() && $proxy->send_through_proxy($url) && !(\defined('ORILLACART_PROXY_FILTERS_PRIVATE') && \constant('ORILLACART_PROXY_FILTERS_PRIVATE') === true)) {
            return new \WP_Error('orillacart_unsafe_address', \sprintf('The request to %s would go through the proxy in WP_PROXY_HOST, which looks the address up itself: the shop cannot hold it to a public one. A proxy that refuses private addresses itself is trusted with define("ORILLACART_PROXY_FILTERS_PRIVATE", true).', $url));
        }
        try {
            $this->pinned[$url] = OutboundRequests::publicAddresses(strtolower((string) parse_url($url, \PHP_URL_HOST)));
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('orillacart_unsafe_address', $e->getMessage());
        }

        return false;
    }

    public function pin(mixed $handle, mixed $args, mixed $url): void
    {
        if (!$handle instanceof \CurlHandle || !\is_array($args) || !\is_string($url)) {
            return;
        }
        $guarded = self::guarded($args, $url);
        if ($guarded === false) {
            return;
        }
        $addresses = $guarded === true ? ($this->pinned[$url] ?? null) : null;
        unset($this->pinned[$url]);
        if ($addresses === null) {
            // Checked by no one (another plugin's filter answered false after ours, or took the guard away): connect nowhere.
            curl_setopt($handle, \CURLOPT_URL, 'http://0.0.0.0:0/');

            return;
        }
        curl_setopt($handle, \CURLOPT_RESOLVE, OutboundRequests::pins($url, $addresses));
        // Nor a proxy from the environment (http_proxy, HTTPS_PROXY), which would look the name up itself.
        curl_setopt($handle, \CURLOPT_PROXY, '');
    }

    /**
     * Whether the request is one to check: true when it asks for it; null when OutboundRequests started it and the
     * argument that asks is gone; false otherwise.
     *
     * @param array<array-key, mixed> $args
     */
    private static function guarded(array $args, string $url): ?bool
    {
        $asked = $args[OutboundRequests::PUBLIC_ONLY] ?? null;
        if ($asked === true) {
            return true;
        }
        if ($asked === null && OutboundRequests::sending($url)) {
            return null;
        }

        return false;
    }
}
