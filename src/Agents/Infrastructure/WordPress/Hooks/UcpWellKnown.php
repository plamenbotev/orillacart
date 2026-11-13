<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress\Hooks;

use OrillaCart\Agents\Application\UcpService;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * /.well-known/ucp at the site's root (design 75): the shop's UCP profile, which agents read first. Public, cached
 * for five minutes, with an ETag, as the spec asks; nothing while the owner has not switched selling through
 * agents on.
 */
#[AsHook('parse_request', method: 'serve', priority: 0)]
final readonly class UcpWellKnown
{
    public function __construct(private UcpService $ucp) {}

    public function serve(): void
    {
        $path = wp_parse_url(\is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '', \PHP_URL_PATH);
        $home = (string) wp_parse_url(home_url('/'), \PHP_URL_PATH);
        if (!\is_string($path) || $path !== rtrim($home, '/') . '/.well-known/ucp' || !$this->ucp->on()) {
            return;
        }
        $body = (string) wp_json_encode($this->ucp->profile(), \JSON_UNESCAPED_SLASHES);
        $etag = '"' . md5($body) . '"';
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('ETag: ' . $etag);
        header('Access-Control-Allow-Origin: *');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
            status_header(304);
            exit;
        }
        status_header(200);
        echo $body;
        exit;
    }
}
