<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Application\Dto\WebhookResponse;
use OrillaCart\Integrations\Application\WebhookSender;

/**
 * Posts webhooks with WordPress's HTTP API: ten seconds at most, no redirects followed (a receiver that moved
 * says so, rather than the body going somewhere else).
 */
final readonly class WordPressWebhookSender implements WebhookSender
{
    private const int TIMEOUT = 10;

    public function post(string $url, string $body, array $headers): WebhookResponse
    {
        $started = hrtime(true);
        $response = wp_remote_post($url, [
            'body' => $body,
            'headers' => $headers + ['User-Agent' => 'OrillaCart (' . home_url('/') . ')'],
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            // Receivers on this computer, as the webhook's address may be for testing, are not "unsafe" here.
            'reject_unsafe_urls' => !self::local($url),
        ]);
        $milliseconds = (int) ((hrtime(true) - $started) / 1_000_000);
        if ($response instanceof \WP_Error) {
            return new WebhookResponse(null, $response->get_error_message(), $milliseconds);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $error = $status >= 200 && $status < 300 ? '' : mb_substr(wp_strip_all_tags(wp_remote_retrieve_body($response)), 0, 200);

        return new WebhookResponse($status, $error, $milliseconds);
    }

    private static function local(string $url): bool
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);

        return \in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
    }
}
