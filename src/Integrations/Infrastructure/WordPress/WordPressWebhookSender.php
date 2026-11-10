<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Application\Dto\WebhookResponse;
use OrillaCart\Integrations\Application\WebhookSender;
use OrillaCart\Shared\WordPress\OutboundRequests;

/**
 * Posts webhooks with WordPress's HTTP API: ten seconds at most, to a public address (OutboundRequests), no
 * redirects followed (a receiver that moved says so, rather than the body going somewhere else).
 */
final readonly class WordPressWebhookSender implements WebhookSender
{
    private const int TIMEOUT = 10;

    public function post(string $url, string $body, array $headers): WebhookResponse
    {
        $started = hrtime(true);
        // To a public address only; one on this computer, for testing, on a site in development.
        $response = OutboundRequests::send($url, [
            'method' => 'POST',
            'body' => $body,
            'headers' => $headers + ['User-Agent' => 'OrillaCart (' . home_url('/') . ')'],
            'timeout' => (float) self::TIMEOUT,
        ], allowLocal: true);
        $milliseconds = (int) ((hrtime(true) - $started) / 1_000_000);
        if ($response instanceof \WP_Error) {
            return new WebhookResponse(null, $response->get_error_message(), $milliseconds);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $error = $status >= 200 && $status < 300 ? '' : mb_substr(wp_strip_all_tags(wp_remote_retrieve_body($response)), 0, 200);

        return new WebhookResponse($status, $error, $milliseconds);
    }
}
