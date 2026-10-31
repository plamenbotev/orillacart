<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Stripe;

use OrillaCart\Payment\Application\Stripe\StripeApi;
use OrillaCart\Payment\Application\Stripe\StripeRefused;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use Psr\Log\LoggerInterface;

/**
 * Stripe's REST API over the WordPress HTTP API, so proxies and the site's HTTP settings apply. The API version
 * is pinned, so what Stripe answers does not change when the account's default version does.
 */
final readonly class WordPressStripeApi implements StripeApi
{
    public const string BASE = 'https://api.stripe.com';
    public const string VERSION = '2024-06-20';

    private const int TIMEOUT = 30;

    public function __construct(private LoggerInterface $logger) {}

    public function request(string $secretKey, string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $url = self::BASE . $path;
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        if ($method === 'GET' && $body !== '') {
            $url .= '?' . $body;
        }
        $headers = [
            'Authorization' => 'Bearer ' . $secretKey,
            'Stripe-Version' => self::VERSION,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $response = wp_remote_request($url, [
            'method' => $method,
            'timeout' => self::TIMEOUT,
            'headers' => $headers,
            'body' => $method === 'GET' ? '' : $body,
        ]);
        if ($response instanceof \WP_Error) {
            throw new PaymentProviderUnavailable('Stripe', "Stripe could not be reached: {$response->get_error_message()}");
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $answer = \is_array($decoded) ? $decoded : [];
        if ($status < 300) {
            return $answer;
        }
        $error = \is_array($answer['error'] ?? null) ? $answer['error'] : [];
        $message = self::text($error, 'message');
        $code = self::text($error, 'code');
        $context = ['method' => $method, 'path' => $path, 'status' => $status, 'code' => $code, 'message' => $message, 'request' => self::header($response, 'request-id')];
        if (\in_array($status, [400, 402, 404, 409], true)) {
            // What was asked is refused: the caller says why, to the shop manager or the customer.
            $this->logger->info('Stripe refused {method} {path} ({status} {code}): {message} Request id: {request}', $context);

            throw new StripeRefused($message === '' ? "Stripe answered {$status}." : $message, $code);
        }
        $this->logger->error('Stripe answered {method} {path} with {status} ({code}): {message} Request id: {request}', $context);

        throw new PaymentProviderUnavailable('Stripe', match (true) {
            $status === 401 => 'Stripe refused the secret key; check it under Developers → API keys.',
            $status === 403 => 'The restricted key may not do this; give it write access to Checkout Sessions and Refunds.',
            default => "Stripe answered {$status}" . ($message === '' ? '.' : ": {$message}"),
        });
    }

    /**
     * @param array<mixed> $response
     */
    private static function header(array $response, string $name): string
    {
        $value = wp_remote_retrieve_header($response, $name);

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
