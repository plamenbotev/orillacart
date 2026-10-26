<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Application\ClientDocuments;

/**
 * Client ID metadata documents fetched with WordPress's safe HTTP, which refuses the site's own network: at most
 * 64 KB, no redirects, five seconds. A document is kept for as long as its Cache-Control says, up to a day, and
 * an hour when it says nothing.
 */
final readonly class WordPressClientDocuments implements ClientDocuments
{
    private const string KEY = 'orillacart_cimd_';
    private const int MOST_BYTES = 65536;

    public function fetch(string $url): ?array
    {
        $key = self::KEY . md5($url);
        $kept = get_transient($key);
        if (\is_array($kept)) {
            return array_filter($kept, \is_string(...), ARRAY_FILTER_USE_KEY);
        }
        $response = wp_safe_remote_get($url, [
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => self::MOST_BYTES,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $document = json_decode(wp_remote_retrieve_body($response), true);
        if (!\is_array($document)) {
            return null;
        }
        $control = wp_remote_retrieve_header($response, 'cache-control');
        $control = \is_array($control) ? implode(',', array_filter($control, \is_string(...))) : $control;
        $seconds = preg_match('/max-age=(\d+)/', $control, $match) === 1 ? min(86400, (int) $match[1]) : 3600;
        if ($seconds > 0 && !str_contains($control, 'no-store')) {
            set_transient($key, $document, $seconds);
        }

        return array_filter($document, \is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
