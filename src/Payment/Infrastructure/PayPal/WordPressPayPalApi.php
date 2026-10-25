<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\PayPal;

use OrillaCart\Extension\Payment\RefundRefused;
use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalCapture;
use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Application\Dto\PayPalOrder;
use OrillaCart\Payment\Application\Dto\PayPalOrderRequest;
use OrillaCart\Payment\Application\Dto\PayPalRefund;
use OrillaCart\Payment\Application\PayPal\PayPalApi;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Security\Secrets;
use Psr\Log\LoggerInterface;

/**
 * PayPal's REST API over the WordPress HTTP API, so proxies and the site's HTTP settings apply. Access tokens
 * are cached in a transient until shortly before they expire.
 */
final readonly class WordPressPayPalApi implements PayPalApi
{
    public const string LIVE = 'https://api-m.paypal.com';
    public const string SANDBOX = 'https://api-m.sandbox.paypal.com';

    private const int TIMEOUT = 30;

    public function __construct(
        private LoggerInterface $logger,
        private Secrets $secrets,
    ) {}

    public function createOrder(PayPalAccount $account, PayPalOrderRequest $request): PayPalOrder
    {
        $response = $this->send($account, 'POST', '/v2/checkout/orders', self::orderBody($request, true), ['PayPal-Request-Id' => $request->requestId]);
        if ($response['status'] === 422 && ($request->breakdown !== null || $request->shipTo !== null)) {
            // PayPal refused the lines or the address, as for a postcode it does not know: the payment goes on
            // without them, as one order for the amount.
            $this->logger->warning('PayPal refused the details of order {invoice} ({issue}); asking again for the amount alone.', ['invoice' => $request->invoiceId, 'issue' => self::issue($response['body'])]);
            $response = $this->send($account, 'POST', '/v2/checkout/orders', self::orderBody($request, false), ['PayPal-Request-Id' => "{$request->requestId}-plain"]);
        }
        if ($response['status'] >= 300) {
            throw $this->unavailable("PayPal refused to create order {$request->invoiceId} ({$response['status']}).", $response['body']);
        }
        $order = $response['body'];

        $id = self::text($order, 'id');
        foreach (\is_array($order['links'] ?? null) ? $order['links'] : [] as $link) {
            if (\is_array($link) && \in_array($link['rel'] ?? null, ['payer-action', 'approve'], true) && \is_string($link['href'] ?? null) && $id !== '') {
                return new PayPalOrder($id, $link['href']);
            }
        }

        throw $this->unavailable('PayPal created an order without an approval link.', $order);
    }

    /**
     * The order PayPal shows the customer: its lines and what the amount is made of, and the address the goods go to,
     * which PayPal's seller protection needs.
     *
     * @return array<string, mixed>
     */
    private static function orderBody(PayPalOrderRequest $request, bool $detailed): array
    {
        $money = static fn(string $value): array => ['currency_code' => $request->currency, 'value' => $value];
        $amount = $money($request->amount);
        $unit = ['invoice_id' => $request->invoiceId, 'custom_id' => $request->customId, 'description' => $request->description];
        $breakdown = $detailed ? $request->breakdown : null;
        if ($breakdown !== null) {
            $amount['breakdown'] = array_filter([
                'item_total' => $money($breakdown['itemTotal']),
                'tax_total' => $money($breakdown['tax']),
                'shipping' => $money($breakdown['shipping']),
                'discount' => $money($breakdown['discount']),
                'handling' => $money($breakdown['handling']),
            ], static fn(array $part, string $key): bool => $key === 'item_total' || !Money::of($part['value'])->isZero(), ARRAY_FILTER_USE_BOTH);
            if ($request->items !== []) {
                $unit['items'] = array_map(static fn(array $item): array => array_filter([
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'quantity' => (string) $item['quantity'],
                    'unit_amount' => $money($item['unitAmount']),
                ], static fn(mixed $value): bool => $value !== null && $value !== ''), $request->items);
            }
        }
        $unit['amount'] = $amount;
        $shipTo = $detailed ? $request->shipTo : null;
        if ($shipTo !== null) {
            $unit['shipping'] = [
                'type' => 'SHIPPING',
                'name' => ['full_name' => mb_substr($shipTo->name, 0, 300)],
                'address' => array_filter([
                    'address_line_1' => mb_substr($shipTo->street, 0, 300),
                    'admin_area_2' => mb_substr($shipTo->city, 0, 120),
                    'admin_area_1' => $shipTo->state,
                    'postal_code' => $shipTo->postcode,
                    'country_code' => $shipTo->country,
                ], static fn(?string $value): bool => $value !== null && $value !== ''),
            ];
        }

        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => self::brand(),
                'user_action' => 'PAY_NOW',
                // The customer cannot change the address at PayPal: the shop charged for shipping to this one.
                'shipping_preference' => $shipTo === null ? 'NO_SHIPPING' : 'SET_PROVIDED_ADDRESS',
                'return_url' => $request->returnUrl,
                'cancel_url' => $request->cancelUrl,
            ]]],
        ];
    }

    public function captureOrder(PayPalAccount $account, string $orderId): PayPalCapture
    {
        $path = '/v2/checkout/orders/' . rawurlencode($orderId);
        $response = $this->send($account, 'POST', $path . '/capture', new \stdClass(), ['PayPal-Request-Id' => "orillacart-capture-{$orderId}"]);
        if ($response['status'] === 422 && self::issue($response['body']) === 'ORDER_ALREADY_CAPTURED') {
            $response = $this->send($account, 'GET', $path);
        }
        if ($response['status'] === 422 && self::issue($response['body']) === 'INSTRUMENT_DECLINED') {
            // The card or account the customer chose was declined: a payment that failed, not PayPal away; the
            // order may be paid again, another way.
            return new PayPalCapture('', 'DECLINED', '0', '', $orderId);
        }
        if ($response['status'] >= 300) {
            throw $this->unavailable("PayPal refused to capture order {$orderId} ({$response['status']}).", $response['body']);
        }

        $capture = self::firstCapture($response['body']);
        if (!\is_array($capture)) {
            throw $this->unavailable("PayPal order {$orderId} has no capture.", $response['body']);
        }

        return self::captureOf($capture, $orderId);
    }

    /**
     * @param array<mixed> $capture
     */
    private static function captureOf(array $capture, string $orderId): PayPalCapture
    {
        $amount = \is_array($capture['amount'] ?? null) ? $capture['amount'] : [];

        return new PayPalCapture(self::text($capture, 'id'), self::text($capture, 'status'), self::text($amount, 'value'), self::text($amount, 'currency_code'), $orderId);
    }

    /**
     * @param array<mixed> $capture
     */
    private static function relatedOrder(array $capture): string
    {
        $supplementary = \is_array($capture['supplementary_data'] ?? null) ? $capture['supplementary_data'] : [];
        $related = \is_array($supplementary['related_ids'] ?? null) ? $supplementary['related_ids'] : [];

        return self::text($related, 'order_id');
    }

    public function refundCapture(PayPalAccount $account, string $captureId, string $requestId, string $amount, string $currency, string $note): PayPalRefund
    {
        // custom_id comes back with the refund's notifications: the shop knows its own refund by it.
        $body = ['amount' => ['value' => $amount, 'currency_code' => $currency], 'custom_id' => $requestId];
        if (trim($note) !== '') {
            $body['note_to_payer'] = mb_substr(trim($note), 0, 255);
        }
        $response = $this->send($account, 'POST', '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', $body, ['PayPal-Request-Id' => $requestId]);
        $status = $response['status'];
        if ($status >= 400 && $status < 500 && !\in_array($status, [401, 408, 429], true)) {
            $issue = self::issue($response['body']);
            $description = self::text($response['body'], 'message');
            $this->logger->warning('PayPal refused refund {request} of capture {capture} ({status}): {issue}', ['request' => $requestId, 'capture' => $captureId, 'status' => $status, 'issue' => $issue === '' ? $description : $issue]);

            throw new RefundRefused(match ($issue) {
                'CAPTURE_FULLY_REFUNDED' => 'The payment was refunded in full already.',
                'REFUND_AMOUNT_EXCEEDED' => 'The amount is more than is left of the payment.',
                'REFUND_TIME_LIMIT_EXCEEDED' => 'The payment is too old to refund through PayPal.',
                'INSUFFICIENT_FUNDS' => 'The PayPal account does not have the money to refund.',
                default => $issue === '' ? ($description === '' ? "PayPal answered {$status}." : $description) : $issue,
            });
        }
        if ($status >= 300) {
            throw $this->unavailable("PayPal could not refund capture {$captureId} ({$status}).", $response['body']);
        }

        return new PayPalRefund(self::text($response['body'], 'id'), self::text($response['body'], 'status'));
    }

    public function checkCredentials(PayPalAccount $account): void
    {
        // The shop manager asked and sees the answer: not a problem for the log.
        $this->token($account, logged: false);
    }

    public function capture(PayPalAccount $account, string $captureId): PayPalCapture
    {
        $capture = $this->call($account, 'GET', '/v2/payments/captures/' . rawurlencode($captureId));

        return self::captureOf($capture, self::relatedOrder($capture));
    }

    public function order(PayPalAccount $account, string $orderId): ?array
    {
        $response = $this->send($account, 'GET', '/v2/checkout/orders/' . rawurlencode($orderId));
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] >= 300) {
            throw $this->unavailable("PayPal could not tell how order {$orderId} stands ({$response['status']}).", $response['body']);
        }
        $capture = self::firstCapture($response['body']);

        return ['status' => self::text($response['body'], 'status'), 'capture' => $capture === null ? null : self::captureOf($capture, $orderId)];
    }

    public function refund(PayPalAccount $account, string $refundId): PayPalRefund
    {
        $refund = $this->call($account, 'GET', '/v2/payments/refunds/' . rawurlencode($refundId));

        return new PayPalRefund(self::text($refund, 'id'), self::text($refund, 'status'));
    }

    public function verifyNotification(PayPalAccount $account, PayPalNotification $notification): bool
    {
        try {
            json_decode($notification->body, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        $header = static fn(string $name): string => $notification->headers[$name] ?? '';
        // The event goes back exactly as received: re-encoding it could change what PayPal signed.
        $fields = (string) json_encode([
            'auth_algo' => $header('paypal-auth-algo'),
            'cert_url' => $header('paypal-cert-url'),
            'transmission_id' => $header('paypal-transmission-id'),
            'transmission_sig' => $header('paypal-transmission-sig'),
            'transmission_time' => $header('paypal-transmission-time'),
            'webhook_id' => $account->webhookId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $body = substr($fields, 0, -1) . ',"webhook_event":' . $notification->body . '}';

        $response = $this->send($account, 'POST', '/v1/notifications/verify-webhook-signature', $body);
        if ($response['status'] >= 300) {
            throw $this->unavailable("PayPal could not verify a webhook ({$response['status']}).", $response['body']);
        }

        return ($response['body']['verification_status'] ?? null) === 'SUCCESS';
    }

    /**
     * @param array<string, mixed>|\stdClass|null $body
     * @param array<string, string>               $headers
     *
     * @return array<mixed>
     */
    private function call(PayPalAccount $account, string $method, string $path, array|\stdClass|null $body = null, array $headers = []): array
    {
        $response = $this->send($account, $method, $path, $body, $headers);
        if ($response['status'] >= 300) {
            throw $this->unavailable("PayPal answered {$method} {$path} with {$response['status']}.", $response['body']);
        }

        return $response['body'];
    }

    /**
     * @param array<string, mixed>|\stdClass|string|null $body a string is sent as it is
     * @param array<string, string>                      $headers
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function send(PayPalAccount $account, string $method, string $path, array|\stdClass|string|null $body = null, array $headers = [], bool $retry = true): array
    {
        $response = wp_remote_request(self::base($account) . $path, [
            'method' => $method,
            'timeout' => self::TIMEOUT,
            'headers' => ['Authorization' => 'Bearer ' . $this->token($account), 'Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers,
            'body' => $body === null ? '' : (\is_string($body) ? $body : (string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ]);
        if ($response instanceof \WP_Error) {
            throw $this->unavailable("PayPal could not be reached: {$response->get_error_message()}", []);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401 && $retry) {
            delete_transient(self::tokenKey($account));

            return $this->send($account, $method, $path, $body, $headers, false);
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        return ['status' => $status, 'body' => \is_array($decoded) ? $decoded : []];
    }

    private function token(PayPalAccount $account, bool $logged = true): string
    {
        // Sealed, in a transient that expires, so never loaded with every page.
        $cached = get_transient(self::tokenKey($account));
        $opened = \is_string($cached) ? $this->secrets->open($cached) : null;
        if ($opened !== null && $opened !== '' && Secrets::isSealed($cached)) {
            return $opened;
        }

        $response = wp_remote_post(self::base($account) . '/v1/oauth2/token', [
            'timeout' => self::TIMEOUT,
            'headers' => ['Authorization' => 'Basic ' . base64_encode("{$account->clientId}:{$account->clientSecret}"), 'Accept' => 'application/json'],
            'body' => ['grant_type' => 'client_credentials'],
        ]);
        if ($response instanceof \WP_Error) {
            throw $this->unavailable("PayPal could not be reached: {$response->get_error_message()}", [], $logged);
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $token = \is_array($decoded) ? self::text($decoded, 'access_token') : '';
        if ($token === '') {
            throw $this->unavailable('PayPal refused the credentials (' . wp_remote_retrieve_response_code($response) . '); check the client ID and secret.', \is_array($decoded) ? $decoded : [], $logged);
        }
        $lifetime = \is_array($decoded) && \is_int($decoded['expires_in'] ?? null) ? $decoded['expires_in'] : 600;
        set_transient(self::tokenKey($account), $this->secrets->seal($token), max(60, $lifetime - 120));

        return $token;
    }

    private static function brand(): string
    {
        $name = mb_substr(wp_strip_all_tags(get_bloginfo('name')), 0, 127);

        return $name === '' ? 'Shop' : $name;
    }

    /**
     * @param array<mixed> $order
     *
     * @return array<mixed>|null
     */
    private static function firstCapture(array $order): ?array
    {
        $unit = \is_array($order['purchase_units'] ?? null) && \is_array($order['purchase_units'][0] ?? null) ? $order['purchase_units'][0] : [];
        $payments = \is_array($unit['payments'] ?? null) ? $unit['payments'] : [];
        $captures = \is_array($payments['captures'] ?? null) ? $payments['captures'] : [];

        return \is_array($captures[0] ?? null) ? $captures[0] : null;
    }

    private static function base(PayPalAccount $account): string
    {
        return $account->sandbox ? self::SANDBOX : self::LIVE;
    }

    private static function tokenKey(PayPalAccount $account): string
    {
        return 'orillacart_paypal_' . substr(hash('sha256', ($account->sandbox ? 'sandbox:' : 'live:') . $account->clientId . ':' . $account->clientSecret), 0, 32);
    }

    /**
     * @param array<mixed> $body
     */
    private static function issue(array $body): string
    {
        $details = \is_array($body['details'] ?? null) ? $body['details'] : [];

        return \is_array($details[0] ?? null) ? self::text($details[0], 'issue') : '';
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<mixed> $body
     */
    private function unavailable(string $detail, array $body, bool $logged = true): PaymentProviderUnavailable
    {
        if (!$logged) {
            return new PaymentProviderUnavailable('PayPal', $detail);
        }
        $this->logger->error('{detail} PayPal debug id: {debugId}; issue: {issue}', [
            'detail' => $detail,
            'debugId' => self::text($body, 'debug_id'),
            'issue' => implode(' ', array_filter([self::issue($body), self::text($body, 'error_description'), self::text($body, 'message')], static fn(string $part): bool => $part !== '')),
        ]);

        return new PaymentProviderUnavailable('PayPal', $detail);
    }
}
