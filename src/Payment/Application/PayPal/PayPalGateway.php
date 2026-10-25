<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\PayPal;

use OrillaCart\Extension\Payment\ChecksPayments;
use OrillaCart\Extension\Payment\ChecksRefunds;
use OrillaCart\Extension\Payment\HandlesReturns;
use OrillaCart\Extension\Payment\InvalidNotification;
use OrillaCart\Extension\Payment\NamesAccounts;
use OrillaCart\Extension\Payment\NamesNotifiedEvents;
use OrillaCart\Extension\Payment\NamesNotifiedPayments;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentItem;
use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Payment\RefundRefused;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\Payment\RefundResult;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\TestsSettings;
use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalCapture;
use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Application\Dto\PayPalOrderRequest;
use OrillaCart\Payment\Application\Dto\PayPalRefund;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Shared\Domain\Money;

/**
 * PayPal Checkout through the Orders API v2: the shop creates a PayPal order for the amount, the customer
 * approves it at PayPal and comes back, and the shop captures it. A verified webhook settles payments whose
 * customer never came back, and tells of refunds made in PayPal's own dashboard. Refunds go back to the capture. Built on the extension API, as an add-on's gateway would be.
 */
final readonly class PayPalGateway implements PaymentGateway, NamesAccounts, HandlesReturns, NamesNotifiedPayments, NamesNotifiedEvents, ChecksPayments, ChecksRefunds, TestsSettings
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
            new Setting('webhook_id', 'Webhook ID', help: 'Needed for live payments. PayPal tells the shop of payments of customers who close the window before coming back, of reviews, refunds made at PayPal, reversals and disputes. Subscribe the webhook to the shop\'s webhook address with all events.'),
        ];
    }

    public function notReady(Settings $settings, string $currency, int $decimals): ?string
    {
        $account = self::credentials($settings);

        return match (true) {
            $account->clientId === '' || $account->clientSecret === '' => 'Enter the client ID and secret.',
            // Live payments need the webhook: without it the shop hears of reversals and disputes from nobody.
            !$account->sandbox && $account->webhookId === '' => 'Enter the webhook ID: live payments need it.',
            !isset(self::CURRENCIES[$currency]) => "PayPal does not take {$currency}.",
            $decimals > self::CURRENCIES[$currency] => \sprintf('PayPal takes amounts in %s with %d decimals; set the shop’s price decimals to %d.', $currency, self::CURRENCIES[$currency], self::CURRENCIES[$currency]),
            default => null,
        };
    }

    public function start(PaymentSession $session, Settings $settings): PaymentStart
    {
        $amount = Money::of($session->amount);
        $decimals = self::CURRENCIES[$session->currency] ?? throw new ProviderUnavailable('PayPal', "PayPal does not take {$session->currency}.");
        if (!$amount->rounded($decimals)->equals($amount)) {
            throw new ProviderUnavailable('PayPal', "The amount {$amount} has more decimals than PayPal takes in {$session->currency}.");
        }

        $breakdown = $session->breakdown;
        $parts = $breakdown === null ? [] : ['itemTotal' => $breakdown->itemTotal, 'tax' => $breakdown->tax, 'shipping' => $breakdown->shipping, 'discount' => $breakdown->discount, 'handling' => $breakdown->handling];
        foreach ($session->items as $item) {
            $parts[] = $item->unitAmount;
        }
        // The shop's decimals are never more than PayPal's (notReady), but an add-on's session might be.
        $fits = array_all($parts, static fn(string $part): bool => Money::of($part)->rounded($decimals)->equals(Money::of($part)));
        $order = $this->call(fn() => $this->api->createOrder(self::credentials($settings), new PayPalOrderRequest(
            "orillacart-payment-{$session->paymentId}",
            "orillacart-{$session->orderNumber}-{$session->paymentId}",
            (string) $session->orderNumber,
            "Order #{$session->orderNumber}",
            $amount->format($decimals),
            $session->currency,
            $session->returnUrl,
            $session->cancelUrl,
            $fits && $breakdown !== null ? array_map(static fn(PaymentItem $item): array => [
                'name' => mb_substr($item->name, 0, 127),
                'sku' => $item->sku === null ? null : mb_substr($item->sku, 0, 127),
                'quantity' => $item->quantity,
                'unitAmount' => Money::of($item->unitAmount)->format($decimals),
            ], $session->items) : [],
            $fits && $breakdown !== null ? [
                'itemTotal' => Money::of($breakdown->itemTotal)->format($decimals),
                'tax' => Money::of($breakdown->tax)->format($decimals),
                'shipping' => Money::of($breakdown->shipping)->format($decimals),
                'discount' => Money::of($breakdown->discount)->format($decimals),
                'handling' => Money::of($breakdown->handling)->format($decimals),
            ] : null,
            $session->shipTo,
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
     * Coming back to the cancel address cancels only what PayPal says was never approved: one who approved in
     * another tab, then came back from the first, has paid.
     */
    public function returned(string $token, PaymentReturn $return, Settings $settings): PaymentOutcome
    {
        if ($return->cancelled) {
            try {
                $order = $this->api->order(self::credentials($settings), $token);
            } catch (PaymentProviderUnavailable) {
                return PaymentOutcome::pending($token);
            }

            return match (true) {
                $order === null, \in_array($order['status'], ['CREATED', 'PAYER_ACTION_REQUIRED', 'VOIDED'], true) => PaymentOutcome::cancelled($token),
                default => $this->check($token, null, $settings),
            };
        }
        try {
            return self::outcome($token, $this->api->captureOrder(self::credentials($settings), $token));
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
        $refund = $this->call(fn() => $this->api->refundCapture(self::credentials($settings), $request->transactionId, $request->id, $amount->format($decimals), $request->currency, $request->reason));
        if (\in_array($refund->status, ['CANCELLED', 'FAILED'], true)) {
            throw new RefundRefused("PayPal answered the refund with {$refund->status}.");
        }

        return $refund->status === 'PENDING' ? RefundResult::pending($refund->id) : RefundResult::done($refund->id);
    }

    public function check(string $reference, ?string $transactionId, Settings $settings): PaymentOutcome
    {
        $account = self::credentials($settings);
        if ($transactionId !== null && $transactionId !== '') {
            return self::outcome($reference, $this->call(fn(): PayPalCapture => $this->api->capture($account, $transactionId)));
        }
        $order = $this->call(fn(): ?array => $this->api->order($account, $reference));

        return match (true) {
            // Never approved, and expired since.
            $order === null, $order['status'] === 'VOIDED' => PaymentOutcome::cancelled($reference),
            $order['status'] === 'APPROVED' => self::outcome($reference, $this->call(fn(): PayPalCapture => $this->api->captureOrder($account, $reference))),
            $order['capture'] !== null => self::outcome($reference, $order['capture']),
            default => PaymentOutcome::pending($reference),
        };
    }

    public function checkRefund(string $reference, Settings $settings): RefundResult
    {
        $refund = $this->call(fn(): PayPalRefund => $this->api->refund(self::credentials($settings), $reference));
        if (\in_array($refund->status, ['CANCELLED', 'FAILED'], true)) {
            throw new RefundRefused("PayPal gave up on the refund: {$refund->status}.");
        }

        return $refund->status === 'COMPLETED' ? RefundResult::done($refund->id) : RefundResult::pending($refund->id);
    }

    /**
     * An approved order is captured when its notification comes: only for PayPal orders this shop started.
     */
    public function eventId(Notification $notification): ?string
    {
        $event = json_decode($notification->body, true);

        return \is_array($event) && \is_string($event['id'] ?? null) && $event['id'] !== '' ? mb_substr($event['id'], 0, 100) : null;
    }

    public function paymentReference(Notification $notification): ?string
    {
        $event = json_decode($notification->body, true);
        if (!\is_array($event) || ($event['event_type'] ?? null) !== 'CHECKOUT.ORDER.APPROVED') {
            return null;
        }
        $resource = \is_array($event['resource'] ?? null) ? $event['resource'] : [];

        return self::text($resource, 'id');
    }

    public function notified(Notification $notification, Settings $settings): ?PaymentOutcome
    {
        $account = self::credentials($settings);
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
        if ($type === 'PAYMENT.CAPTURE.REVERSED') {
            $supplementary = \is_array($resource['supplementary_data'] ?? null) ? $resource['supplementary_data'] : [];
            $related = \is_array($supplementary['related_ids'] ?? null) ? $supplementary['related_ids'] : [];
            $captureId = self::text($resource, 'id');

            return $captureId === '' ? null : PaymentOutcome::disputed(self::text($related, 'order_id'), 'PayPal reversed the payment, as for a chargeback the customer\'s bank made.', $captureId);
        }
        if (str_starts_with($type, 'CUSTOMER.DISPUTE.')) {
            $disputed = \is_array($resource['disputed_transactions'] ?? null) && \is_array($resource['disputed_transactions'][0] ?? null) ? $resource['disputed_transactions'][0] : [];
            $captureId = self::text($disputed, 'seller_transaction_id');
            $what = match ($type) {
                'CUSTOMER.DISPUTE.CREATED' => 'The customer opened a dispute at PayPal',
                'CUSTOMER.DISPUTE.RESOLVED' => 'The dispute at PayPal was resolved',
                default => 'The dispute at PayPal changed',
            };
            $reason = trim(self::text($resource, 'reason') . ' ' . self::text($resource, 'status'));

            return $captureId === '' ? null : PaymentOutcome::disputed('', $reason === '' ? "{$what}." : "{$what} ({$reason}).", $captureId);
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
            if (!\in_array(self::text($resource, 'status'), ['', 'COMPLETED'], true)) {
                // Not done yet: the shop asks again later, and records it once it is.
                return null;
            }

            return $refundId === '' || ($captureId === '' && self::text($related, 'order_id') === '') ? null : PaymentOutcome::refunded(
                self::text($related, 'order_id'),
                $refundId,
                self::text($amount, 'value'),
                self::text($amount, 'currency_code'),
                $captureId === '' ? null : $captureId,
                self::text($resource, 'custom_id') === '' ? null : self::text($resource, 'custom_id'),
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

    /** Sandbox or live, and whose app: a payment is asked about, and refunded, only there. */
    public function account(Settings $settings): string
    {
        $account = self::credentials($settings);

        return ($account->sandbox ? 'sandbox:' : 'live:') . $account->clientId;
    }

    /** Gets a token with the keys: PayPal's own answer when it refuses them. */
    public function test(Settings $settings): ?string
    {
        $account = self::credentials($settings);
        if ($account->clientId === '' || $account->clientSecret === '') {
            return 'Enter the client ID and secret.';
        }
        try {
            $this->api->checkCredentials($account);
        } catch (PaymentProviderUnavailable $e) {
            return $e->detail !== '' ? $e->detail : $e->getMessage();
        }

        return null;
    }

    public static function credentials(Settings $settings): PayPalAccount
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
            'DECLINED', 'FAILED' => PaymentOutcome::failed($orderId, $capture->id === '' ? null : $capture->id),
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
