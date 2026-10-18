<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\OpenSearch;

use OrillaCart\Search\Domain\EngineSettings;

/**
 * Calls OpenSearch's REST API with the WordPress HTTP API, as JSON, with basic authentication when set.
 */
final readonly class OpenSearchClient
{
    private const int TIMEOUT = 5;

    /**
     * @param array<mixed>|string|null $body JSON to send, or NDJSON for _bulk
     *
     * @return array<mixed> the answer; empty for HEAD and 404 when $missingOk
     *
     * @throws OpenSearchUnavailable
     */
    public function request(EngineSettings $settings, string $method, string $path, array|string|null $body = null, bool $missingOk = false): array
    {
        $headers = ['Content-Type' => \is_string($body) ? 'application/x-ndjson' : 'application/json'];
        if ($settings->username !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($settings->username . ':' . $settings->password);
        }
        $arguments = ['method' => $method, 'headers' => $headers, 'timeout' => self::TIMEOUT];
        if ($body !== null) {
            $arguments['body'] = \is_string($body) ? $body : (string) wp_json_encode($body);
        }
        $response = wp_remote_request(rtrim($settings->host, '/') . '/' . ltrim($path, '/'), $arguments);
        if ($response instanceof \WP_Error) {
            throw new OpenSearchUnavailable(\sprintf('OpenSearch could not be reached: %s', $response->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 404 && $missingOk) {
            return [];
        }
        $answer = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $reason = \is_array($answer) && \is_array($answer['error'] ?? null) && \is_string($answer['error']['reason'] ?? null) ? $answer['error']['reason'] : (string) wp_remote_retrieve_response_message($response);

            throw new OpenSearchUnavailable(\sprintf('OpenSearch answered %d: %s', $status, $reason));
        }

        return \is_array($answer) ? $answer : [];
    }
}
