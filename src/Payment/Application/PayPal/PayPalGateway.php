<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\PayPal;

use OrillaCart\Extension\Payment\HandlesNotifications;
use OrillaCart\Extension\Payment\HandlesReturns;
use OrillaCart\Extension\Payment\InvalidNotification;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Payment\RefundRefused;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\Payment\RefundResult;
use OrillaCart\Extension\Payment\RefundsPayments;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Settings;
use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalCapture;
use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Application\Dto\PayPalOrderRequest;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Shared\Domain\Money;

/**
 * PayPal Checkout through the Orders API v2: the shop creates a PayPal order for the amount, the customer
 * approves it at PayPal and comes back, and the shop captures it. A verified webhook settles payments whose
 * customer never came back, and tells of refunds made in PayPal's own dashboard. Refunds go back to the capture. Built on the extension API, as an add-on's gateway would be.
 */
final readonly class PayPalGateway implements PaymentGateway, HandlesReturns, HandlesNotifications, RefundsPayments
{
    public const string ID = 'paypal';

    /** Currencies PayPal accepts, with the decimals it allows in amounts. */
    private const array CURRENCIES = [
        'AUD' => 2, 'BRL' => 2, 'CAD' => 2, 'CNY' => 2, 'CZK' => 2, 'DKK' => 2, 'EUR' => 2, 'HKD' => 2, 'HUF' => 0,
        'ILS' => 2, 'JPY' => 0, 'MYR' => 2, 'MXN' => 2, 'TWD' => 0, 'NZD' => 2, 'NOK' => 2, 'PHP' => 2, 'PLN' => 2,
        'GBP' => 2, 'SGD' => 2, 'SEK' => 2, 'CHF' => 2, 'THB' => 2, 'USD' => 2,
    ];

    public function __construct(private PayPalApi $api) {}

    public function id(): string
    {
        return self::ID;
    }

    public function defaultName(): string
    {
        return 'PayPal';
    }

    public function settings(): array
    {
        return [
            new Setting('client_id', 'Client ID', required: true, help: 'From your REST app at developer.paypal.com.'),
            new Setting('client_secret', 'Secret', Setting::SECRET, required: true),
            new Setting('sandbox', 'Sandbox', Setting::BOOLEAN, help: 'Take test payments with sandbox credentials.'),
            new Setting('webhook_id', 'Webhook ID', help: 'Settles payments of customers who close the window before coming back. Subscribe the webhook to the shop\'s webhook address.'),
        ];
    }

    public function isReady(Settings $settings, string $currency): bool
    {
        $account = self::account($settings);

        return $account->clientId !== '' && $account->clientSecret !== '' && isset(self::CURRENCIES[$currency]);
    }

    public function start(PaymentSession $session, Settings $settings): PaymentStart
    {
        $amount = Money::of($session->amount);
        $decimals = self::CURRENCIES[$session->currency] ?? throw new ProviderUnavailable('PayPal', "PayPal does not take {$session->currency}.");
        if (!$amount->rounded($decimals)->equals($amount)) {
            throw new ProviderUnavailable('PayPal', "The amount {$amount} has more decimals than PayPal takes in {$session->currency}.");
        }

        $order = $this->call(fn() => $this->api->createOrder(self::account($settings), new PayPalOrderRequest(
            "orillacart-payment-{$session->paymentId}",
            "orillacart-{$session->orderNumber}-{$session->paymentId}",
            "Order #{$session->orderNumber}",
            $amount->format($decimals),
            $session->currency,
            $session->returnUrl,
            $session->cancelUrl,
        )));

        return PaymentStart::redirect($order->approveUrl, $order->id);
    }

    /** PayPal adds its order id as "token". */
    public function reference(PaymentReturn $return): ?string
    {
        $token = $return->query['token'] ?? '';

        return $token === '' ? null : $token;
    }

    /**
     * A customer who approved is captured now; when PayPal cannot be reached, the webhook settles the payment.
     */
    public function returned(string $token, PaymentReturn $return, Settings $settings): PaymentOutcome
    {
        if ($return->cancelled) {
            return PaymentOutcome::cancelled($token);
        }
        try {
            return self::outcome($token, $this->api->captureOrder(self::account($settings), $token));
        } catch (PaymentProviderUnavailable) {
            return PaymentOutcome::pending($token);
        }
    }

    public function refund(RefundRequest $request, Settings $settings): RefundResult
    {
        if ($request->transactionId === null || $request->transactionId === '') {
            throw new RefundRefused('PayPal has no capture for this payment to refund.');
        }
        $decimals = self::CURRENCIES[$request->currency] ?? throw new RefundRefused("PayPal does not take {$request->currency}.");
        $amount = Money::of($request->amount);
        if (!$amount->rounded($decimals)->equals($amount)) {
            throw new RefundRefused("The amount {$amount} has more decimals than PayPal takes in {$request->currency}.");
        }
        $refund = $this->call(fn() => $this->api->refundCapture(self::account($settings), $request->transactionId, $request->id, $amount->format($decimals), $request->currency, $request->reason));
        if (\in_array($refund->status, ['CANCELLED', 'FAILED'], true)) {
            throw new RefundRefused("PayPal answered the refund with {$refund->status}.");
        }

        return $refund->status === 'PENDING' ? RefundResult::pending($refund->id) : RefundResult::done($refund->id);
    }

    public function notified(Notification $notification, Settings $settings): ?PaymentOutcome
    {
        $account = self::account($settings);
        $verified = $account->webhookId !== '' && $this->call(fn(): bool => $this->api->verifyNotification($account, new PayPalNotification($notification->headers, $notification->body)));
        if (!$verified) {
            throw new InvalidNotification('The notification is not signed by PayPal for this shop.');
        }
        $event = json_decode($notification->body, true);
        $type = \is_array($event) && \is_string($event['event_type'] ?? null) ? $event['event_type'] : '';
        $resource = \is_array($event) && \is_array($event['resource'] ?? null) ? $event['resource'] : [];

        if ($type === 'CHECKOUT.ORDER.APPROVED') {
            $orderId = self::text($resource, 'id');

            return $orderId === '' ? null : self::outcome($orderId, $this->call(fn(): PayPalCapture => $this->api->captureOrder($account, $orderId)));
        }
        if ($type === 'PAYMENT.CAPTURE.REFUNDED') {
            // The resource is the refund; it links up to the capture it gave money back from.
            $captureId = '';
            foreach (\is_array($resource['links'] ?? null) ? $resource['links'] : [] as $link) {
                if (\is_array($link) && ($link['rel'] ?? null) === 'up' && \is_string($link['href'] ?? null) && preg_match('#/captures/([^/?]+)#', $link['href'], $match) === 1) {
                    $captureId = $match[1];
                }
            }
            $supplementary = \is_array($resource['supplementary_data'] ?? null) ? $resource['supplementary_data'] : [];
            $related = \is_array($supplementary['related_ids'] ?? null) ? $supplementary['related_ids'] : [];
            $amount = \is_array($resource['amount'] ?? null) ? $resource['amount'] : [];
            $refundId = self::text($resource, 'id');

            return $refundId === '' || ($captureId === '' && self::text($related, 'order_id') === '') ? null : PaymentOutcome::refunded(
                self::text($related, 'order_id'),
                $refundId,
                self::text($amount, 'value'),
                self::text($amount, 'currency_code'),
                $captureId === '' ? null : $captureId,
            );
        }
        if (str_starts_with($type, 'PAYMENT.CAPTURE.')) {
            $supplementary = \is_array($resource['supplementary_data'] ?? null) ? $resource['supplementary_data'] : [];
            $related = \is_array($supplementary['related_ids'] ?? null) ? $supplementary['related_ids'] : [];
            $orderId = self::text($related, 'order_id');
            $amount = \is_array($resource['amount'] ?? null) ? $resource['amount'] : [];

            return $orderId === '' ? null : self::outcome($orderId, new PayPalCapture(
                self::text($resource, 'id'),
                self::text($resource, 'status'),
                self::text($amount, 'value'),
                self::text($amount, 'currency_code'),
                $orderId,
            ));
        }

        return null;
    }

    public static function account(Settings $settings): PayPalAccount
    {
        return new PayPalAccount(trim($settings->text('client_id')), trim($settings->text('client_secret')), $settings->flag('sandbox'), trim($settings->text('webhook_id')));
    }

    public static function decimals(string $currency): ?int
    {
        return self::CURRENCIES[$currency] ?? null;
    }

    private static function outcome(string $orderId, PayPalCapture $capture): PaymentOutcome
    {
        return match ($capture->status) {
            'COMPLETED' => PaymentOutcome::completed($orderId, $capture->id, $capture->amount, $capture->currency),
            'PENDING' => PaymentOutcome::held($orderId, $capture->id),
            'DECLINED', 'FAILED' => PaymentOutcome::failed($orderId, $capture->id),
            default => PaymentOutcome::pending($orderId),
        };
    }

    /**
     * Runs a call to PayPal, reporting its failure as the extension API does.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     *
     * @throws ProviderUnavailable
     */
    private function call(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (PaymentProviderUnavailable $e) {
            throw new ProviderUnavailable('PayPal', $e->detail, $e);
        }
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
