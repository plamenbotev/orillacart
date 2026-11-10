<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Shared\WordPress\OutboundRequests;

/**
 * For a request sent through OutboundRequests: the host is resolved just before it connects and refused unless
 * every address is public, and the connection is pinned to those addresses (CURLOPT_RESOLVE), so DNS cannot say
 * one thing to the check and another to the connection. Last of the filters: a request a test or another plugin
 * answers itself connects nowhere and is not second-guessed.
 */
#[AsHook('pre_http_request', method: 'check', filter: true, priority: \PHP_INT_MAX, arguments: 3)]
#[AsHook('http_api_curl', method: 'pin', priority: 10, arguments: 3)]
final class PublicOnlyRequests
{
    /** @var array<string, list<string>> url → the addresses it was checked at, until it connects */
    private array $pinned = [];

    public function check(mixed $pre, mixed $args, mixed $url): mixed
    {
        if ($pre !== false || !\is_array($args) || ($args[OutboundRequests::PUBLIC_ONLY] ?? false) !== true || !\is_string($url)) {
            return $pre;
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
        if (!$handle instanceof \CurlHandle || !\is_array($args) || ($args[OutboundRequests::PUBLIC_ONLY] ?? false) !== true || !\is_string($url)) {
            return;
        }
        $addresses = $this->pinned[$url] ?? null;
        unset($this->pinned[$url]);
        if ($addresses === null) {
            // Checked by no one (another plugin's filter answered false after ours): connect nowhere.
            curl_setopt($handle, \CURLOPT_URL, 'http://0.0.0.0:0/');

            return;
        }
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $port = parse_url($url, \PHP_URL_PORT) ?? (parse_url($url, \PHP_URL_SCHEME) === 'http' ? 80 : 443);
        $written = array_map(static fn(string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address, $addresses);
        curl_setopt($handle, \CURLOPT_RESOLVE, [\sprintf('%s:%d:%s', trim($host, '[]'), $port, implode(',', $written))]);
    }
}
