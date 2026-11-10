<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Stripe;

use OrillaCart\Extension\Payment\ChecksPayments;
use OrillaCart\Extension\Payment\ChecksRefunds;
use OrillaCart\Extension\Payment\HandlesReturns;
use OrillaCart\Extension\Payment\InvalidNotification;
use OrillaCart\Extension\Payment\NamesAccounts;
use OrillaCart\Extension\Payment\NamesNotifiedEvents;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentGateway;
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
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Stripe Checkout: the shop opens a Checkout Session for the amount, the customer pays on Stripe's page with
 * whatever the Stripe account offers (cards with 3-D Secure, wallets, Link, bank debits, pay later) and comes
 * back. A signed webhook settles payments whose customer never came back, and those that take days (bank
 * debits); it tells of refunds made in Stripe's dashboard, and of disputes. Refunds go back to the payment
 * intent. Built on the extension API, as an add-on's gateway would be.
 */
final readonly class StripeGateway implements PaymentGateway, NamesAccounts, HandlesReturns, NamesNotifiedEvents, ChecksPayments, ChecksRefunds, TestsSettings
{
    public const string ID = 'stripe';

    /** How old a webhook's signature may be: Stripe's own libraries allow five minutes. */
    private const int TOLERANCE = 300;

    /** Currencies Stripe takes in whole units. */
    private const array ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    /** Currencies Stripe takes in thousandths, the last of them 0. */
    private const array THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];
    /** Currencies Stripe takes in hundredths, but only of whole amounts. */
    private const array WHOLE_IN_HUNDREDTHS = ['ISK', 'UGX'];

    public function __construct(
        private StripeApi $api,
        private ClockInterface $clock,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function defaultName(): string
    {
        return 'Card';
    }

    public function settings(): array
    {
        return [
            new Setting('secret_key', 'Secret key', Setting::SECRET, required: true, goesWith: [], help: 'From Developers → API keys in the Stripe dashboard: the secret key (sk_…), or a restricted key (rk_…) that may write Checkout Sessions and Refunds.'),
            new Setting('sandbox', 'Test mode', Setting::BOOLEAN, help: 'Take test payments with a test key (sk_test_…).'),
            new Setting('webhook_secret', 'Webhook signing secret', Setting::SECRET, goesWith: [], help: 'whsec_…, needed for live payments. In Stripe, add an endpoint at the shop\'s webhook address with the events checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, refund.created, refund.updated, charge.refunded and charge.dispute.created, .updated and .closed.'),
        ];
    }

    public function notReady(Settings $settings, string $currency, int $decimals): ?string
    {
        $key = self::key($settings);
        $test = self::isTestKey($key);

        return match (true) {
            $key === '' => 'Enter the secret key.',
            preg_match('/^(sk|rk)_(test|live)_/', $key) !== 1 => 'The secret key starts with sk_ or rk_: copy it from Developers → API keys.',
            $settings->flag('sandbox') && !$test => 'Test mode takes a test key (sk_test_…).',
            !$settings->flag('sandbox') && $test => 'The key is a test key: turn on Test mode, or enter the live key.',
            // Live payments need the webhook: without it the shop hears of bank debits, refunds made at Stripe and disputes from nobody.
            !$settings->flag('sandbox') && self::webhookSecret($settings) === '' => 'Enter the webhook signing secret: live payments need it.',
            $decimals > self::shopDecimals($currency) => \sprintf('Stripe takes amounts in %s with %d decimals; set the shop’s price decimals to %d.', $currency, self::shopDecimals($currency), self::shopDecimals($currency)),
            default => null,
        };
    }

    public function start(PaymentSession $session, Settings $settings): PaymentStart
    {
        $currency = strtoupper($session->currency);
        $amount = Money::of($session->amount);
        if (!$amount->rounded(self::shopDecimals($currency))->equals($amount)) {
            throw new ProviderUnavailable('Stripe', "The amount {$amount} has more decimals than Stripe takes in {$currency}.");
        }
        $metadata = ['orillacart_order' => (string) $session->orderNumber, 'orillacart_payment' => (string) $session->paymentId];
        $intent = ['description' => "Order #{$session->orderNumber}", 'metadata' => $metadata];
        if ($session->shipTo !== null) {
            // Radar weighs where the goods go.
            $intent['shipping'] = ['name' => mb_substr($session->shipTo->name, 0, 200), 'address' => array_filter([
                'line1' => $session->shipTo->street,
                'city' => $session->shipTo->city,
                'postal_code' => $session->shipTo->postcode,
                'state' => $session->shipTo->state,
                'country' => $session->shipTo->country,
            ], static fn(?string $value): bool => $value !== null && $value !== '')];
        }
        $params = [
            'mode' => 'payment',
            'client_reference_id' => (string) $session->orderNumber,
            // Stripe puts the session's id in for {CHECKOUT_SESSION_ID}: the return names its payment by it.
            'success_url' => $session->returnUrl . (str_contains($session->returnUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $session->cancelUrl,
            'line_items' => self::lines($session, $currency, $amount),
            'metadata' => $metadata,
            'payment_intent_data' => $intent,
        ];
        if ($session->email !== null && filter_var($session->email, FILTER_VALIDATE_EMAIL) !== false) {
            $params['customer_email'] = $session->email;
        }
        if ($session->payBy !== null) {
            // The session ends with the order's payment window, so no money comes for an order the shop cancelled;
            // within what Stripe allows, half an hour to a day from now.
            $now = $this->clock->now()->getTimestamp();
            $params['expires_at'] = min(max($session->payBy->getTimestamp(), $now + 1860), $now + 86400);
        }
        // The same payment opens the same session, however often it is asked; the site's address keeps a copy of
        // the site (a staging site on the same Stripe account) from getting this one's.
        try {
            $checkout = $this->call(fn(): array => $this->api->request(self::key($settings), 'POST', '/v1/checkout/sessions', $params, 'orillacart-' . substr(hash('sha256', $session->notificationUrl), 0, 12) . "-payment-{$session->paymentId}"));
        } catch (StripeRefused $e) {
            // Such as an amount below Stripe's least charge: the customer pays another way.
            throw new ProviderUnavailable('Stripe', $e->getMessage(), $e);
        }
        $id = self::text($checkout, 'id');
        $url = self::text($checkout, 'url');
        if ($id === '' || $url === '') {
            throw new ProviderUnavailable('Stripe', 'Stripe opened a Checkout Session without its address.');
        }

        return PaymentStart::redirect($url, $id);
    }

    /** Stripe adds the session's id to the return address as session_id; the cancel address names none. */
    public function reference(PaymentReturn $return): ?string
    {
        $id = $return->query['session_id'] ?? '';

        return str_starts_with($id, 'cs_') ? $id : null;
    }

    /**
     * A customer who paid comes back to a paid session, or one whose bank debit is on its way. One who went back
     * without paying has their session closed, so that it cannot be paid after they chose another way to pay;
     * a session they paid in another tab meanwhile stays paid.
     */
    public function returned(string $reference, PaymentReturn $return, Settings $settings): PaymentOutcome
    {
        try {
            if ($return->cancelled) {
                try {
                    $this->api->request(self::key($settings), 'POST', '/v1/checkout/sessions/' . rawurlencode($reference) . '/expire');

                    return PaymentOutcome::cancelled($reference);
                } catch (StripeRefused) {
                    // Paid or closed already: it is told below as it stands.
                }
            }

            return self::outcome($this->session($reference, $settings));
        } catch (PaymentProviderUnavailable|StripeRefused) {
            return PaymentOutcome::pending($reference);
        }
    }

    public function check(string $reference, ?string $transactionId, Settings $settings): PaymentOutcome
    {
        try {
            return self::outcome($this->call(fn(): array => $this->session($reference, $settings)));
        } catch (StripeRefused $e) {
            if ($e->stripeCode === 'resource_missing') {
                return PaymentOutcome::cancelled($reference);
            }

            throw new ProviderUnavailable('Stripe', $e->getMessage(), $e);
        }
    }

    public function refund(RefundRequest $request, Settings $settings): RefundResult
    {
        if ($request->transactionId === null || $request->transactionId === '') {
            throw new RefundRefused('Stripe has no payment for this order to refund.');
        }
        $currency = strtoupper($request->currency);
        $amount = Money::of($request->amount);
        if (!$amount->rounded(self::shopDecimals($currency))->equals($amount)) {
            throw new RefundRefused("The amount {$amount} has more decimals than Stripe takes in {$currency}.");
        }
        $params = [
            'payment_intent' => $request->transactionId,
            'amount' => self::minor($amount, $currency),
            // Comes back with the refund's notifications: the shop knows its own refund by it.
            'metadata' => array_filter(['orillacart_refund' => $request->id, 'orillacart_order' => (string) $request->orderNumber, 'note' => mb_substr(trim($request->reason), 0, 500)], static fn(string $value): bool => $value !== ''),
        ];
        try {
            $refund = $this->call(fn(): array => $this->api->request(self::key($settings), 'POST', '/v1/refunds', $params, "{$request->id}-{$request->transactionId}"));
        } catch (StripeRefused $e) {
            throw new RefundRefused(match ($e->stripeCode) {
                'charge_already_refunded' => 'The payment was refunded in full already.',
                'amount_too_large' => 'The amount is more than is left of the payment.',
                'charge_disputed' => 'The payment is disputed: Stripe refunds it only through the dispute.',
                default => $e->getMessage(),
            });
        }

        return self::refundResult($refund);
    }

    public function checkRefund(string $reference, Settings $settings): RefundResult
    {
        try {
            return self::refundResult($this->call(fn(): array => $this->api->request(self::key($settings), 'GET', '/v1/refunds/' . rawurlencode($reference))));
        } catch (StripeRefused $e) {
            throw new RefundRefused($e->getMessage());
        }
    }

    public function eventId(Notification $notification): ?string
    {
        $event = json_decode($notification->body, true);

        return \is_array($event) && \is_string($event['id'] ?? null) && $event['id'] !== '' ? mb_substr($event['id'], 0, 100) : null;
    }

    public function notified(Notification $notification, Settings $settings): ?PaymentOutcome
    {
        if (!$this->signed($notification, self::webhookSecret($settings))) {
            throw new InvalidNotification('The notification is not signed by Stripe for this shop.');
        }
        $event = json_decode($notification->body, true);
        $type = \is_array($event) && \is_string($event['type'] ?? null) ? $event['type'] : '';
        $data = \is_array($event) && \is_array($event['data'] ?? null) ? $event['data'] : [];
        $object = \is_array($data['object'] ?? null) ? $data['object'] : [];

        switch ($type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
            case 'checkout.session.expired':
                return self::text($object, 'id') === '' ? null : self::outcome($object);
            case 'checkout.session.async_payment_failed':
                return self::text($object, 'id') === '' ? null : PaymentOutcome::failed(self::text($object, 'id'), self::intentId($object));
            case 'refund.created':
            case 'refund.updated':
            case 'charge.refund.updated':
                return self::refunded($object, self::text($object, 'payment_intent'));
            case 'charge.refunded':
                // Newest first; webhooks of API versions before 2022-11-15 list the charge's refunds.
                $refunds = \is_array($object['refunds'] ?? null) && \is_array($object['refunds']['data'] ?? null) ? $object['refunds']['data'] : [];

                return \is_array($refunds[0] ?? null) ? self::refunded($refunds[0], self::text($object, 'payment_intent')) : null;
        }
        if (str_starts_with($type, 'charge.dispute.')) {
            $intent = self::text($object, 'payment_intent');
            $reason = str_replace('_', ' ', self::text($object, 'reason'));
            $status = str_replace('_', ' ', self::text($object, 'status'));
            $what = match ($type) {
                'charge.dispute.created' => "The customer disputed the payment with their bank ({$reason}). Answer it in the Stripe dashboard.",
                'charge.dispute.closed' => "The dispute was closed: {$status}.",
                'charge.dispute.funds_withdrawn' => 'Stripe took the disputed amount back from the balance.',
                'charge.dispute.funds_reinstated' => 'Stripe gave the disputed amount back to the balance.',
                default => "The dispute changed: {$status}.",
            };

            return $intent === '' ? null : PaymentOutcome::disputed('', $what, $intent);
        }

        return null;
    }

    /** Test mode or live: a payment is asked about, and refunded, only there. */
    public function account(Settings $settings): string
    {
        return $settings->flag('sandbox') ? 'test' : 'live';
    }

    /** Reads the balance with the key: Stripe's own answer when it refuses it. */
    public function test(Settings $settings): ?string
    {
        if (self::key($settings) === '') {
            return 'Enter the secret key.';
        }
        if (preg_match('/^(sk|rk)_(test|live)_/', self::key($settings)) !== 1) {
            return 'The secret key starts with sk_ or rk_: copy it from Developers → API keys.';
        }
        try {
            $this->api->request(self::key($settings), 'GET', '/v1/balance');
        } catch (PaymentProviderUnavailable $e) {
            return $e->detail !== '' ? $e->detail : $e->getMessage();
        } catch (StripeRefused $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** How many decimals the shop's amounts may have in this currency for Stripe. */
    public static function shopDecimals(string $currency): int
    {
        $currency = strtoupper($currency);

        return \in_array($currency, self::ZERO_DECIMAL, true) || \in_array($currency, self::WHOLE_IN_HUNDREDTHS, true) ? 0 : 2;
    }

    /** The amount in the currency's smallest unit, as Stripe counts it. */
    public static function minor(Money $amount, string $currency): int
    {
        return (int) str_replace('.', '', $amount->format(self::exponent($currency)));
    }

    /** An amount Stripe counted in the currency's smallest unit, as a decimal number. */
    public static function major(int $minor, string $currency): string
    {
        $exponent = self::exponent($currency);
        if ($exponent === 0) {
            return (string) $minor;
        }
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '') . substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    private static function exponent(string $currency): int
    {
        $currency = strtoupper($currency);

        return match (true) {
            \in_array($currency, self::ZERO_DECIMAL, true) => 0,
            \in_array($currency, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

    /**
     * What Stripe's page lists: the order's items, with shipping, tax and fees as lines of their own, when they add
     * up to the amount without a discount (Stripe's lines cannot be negative); otherwise the order as one line.
     *
     * @return list<array<string, mixed>>
     */
    private static function lines(PaymentSession $session, string $currency, Money $amount): array
    {
        $line = static fn(string $name, int $quantity, Money $unit): array => [
            'quantity' => $quantity,
            'price_data' => ['currency' => strtolower($currency), 'unit_amount' => self::minor($unit, $currency), 'product_data' => ['name' => mb_substr($name, 0, 250)]],
        ];
        $whole = [$line("Order #{$session->orderNumber}", 1, $amount)];
        $breakdown = $session->breakdown;
        if ($breakdown === null || $session->items === [] || !Money::of($breakdown->discount)->isZero()) {
            return $whole;
        }
        $decimals = self::shopDecimals($currency);
        $parts = [];
        foreach ($session->items as $item) {
            $parts[] = [$item->name, $item->quantity, Money::of($item->unitAmount)];
        }
        foreach (['Shipping' => $breakdown->shipping, 'Tax' => $breakdown->tax, 'Fees' => $breakdown->handling] as $name => $value) {
            if (!Money::of($value)->isZero()) {
                $parts[] = [$name, 1, Money::of($value)];
            }
        }
        $total = 0;
        foreach ($parts as [, $quantity, $unit]) {
            if ($quantity < 1 || $unit->isNegative() || !$unit->rounded($decimals)->equals($unit)) {
                return $whole;
            }
            $total += self::minor($unit, $currency) * $quantity;
        }

        return $total === self::minor($amount, $currency)
            ? array_map(static fn(array $part): array => $line(...$part), $parts)
            : $whole;
    }

    /**
     * @return array<mixed>
     *
     * @throws StripeRefused
     * @throws PaymentProviderUnavailable
     */
    private function session(string $reference, Settings $settings): array
    {
        return $this->api->request(self::key($settings), 'GET', '/v1/checkout/sessions/' . rawurlencode($reference), ['expand' => ['payment_intent']]);
    }

    /**
     * How a Checkout Session stands. One completed but not paid waits for a bank debit: the provider holds it, so
     * the shop's time for unpaid orders does not cancel it while the money is on its way.
     *
     * @param array<mixed> $session
     */
    private static function outcome(array $session): PaymentOutcome
    {
        $id = self::text($session, 'id');
        $intent = self::intentId($session);
        $intentStatus = \is_array($session['payment_intent'] ?? null) ? self::text($session['payment_intent'], 'status') : '';
        $status = self::text($session, 'status');
        $paid = \in_array(self::text($session, 'payment_status'), ['paid', 'no_payment_required'], true);
        $currency = strtoupper(self::text($session, 'currency'));

        return match (true) {
            $status === 'complete' && $paid && $intent !== null => PaymentOutcome::completed($id, $intent, \is_int($session['amount_total'] ?? null) ? self::major($session['amount_total'], $currency) : null, $currency === '' ? null : $currency),
            $status === 'complete' && \in_array($intentStatus, ['requires_payment_method', 'canceled'], true) => PaymentOutcome::failed($id, $intent),
            $status === 'complete' => PaymentOutcome::held($id, $intent),
            $status === 'expired' => PaymentOutcome::cancelled($id),
            default => PaymentOutcome::pending($id),
        };
    }

    /**
     * @param array<mixed> $refund
     */
    private static function refunded(array $refund, string $intent): ?PaymentOutcome
    {
        $id = self::text($refund, 'id');
        // Not done yet, or failed: the shop asks again about its own, and records one made at Stripe once it is done.
        if ($id === '' || $intent === '' || self::text($refund, 'status') !== 'succeeded' || !\is_int($refund['amount'] ?? null)) {
            return null;
        }
        $metadata = \is_array($refund['metadata'] ?? null) ? $refund['metadata'] : [];
        $request = self::text($metadata, 'orillacart_refund');
        $currency = strtoupper(self::text($refund, 'currency'));

        return PaymentOutcome::refunded('', $id, self::major($refund['amount'], $currency), $currency === '' ? null : $currency, $intent, $request === '' ? null : $request);
    }

    /**
     * @param array<mixed> $refund
     */
    private static function refundResult(array $refund): RefundResult
    {
        $id = self::text($refund, 'id');

        return match (self::text($refund, 'status')) {
            'succeeded' => RefundResult::done($id),
            'failed', 'canceled' => throw new RefundRefused('Stripe could not refund it: ' . (self::text($refund, 'failure_reason') === '' ? self::text($refund, 'status') : str_replace('_', ' ', self::text($refund, 'failure_reason'))) . '.'),
            default => RefundResult::pending($id),
        };
    }

    /**
     * Whether Stripe signed the body with the endpoint's secret, lately: the Stripe-Signature header holds the
     * time and an HMAC-SHA256 of "time.body" per secret the endpoint has (two while one is being rolled).
     */
    private function signed(Notification $notification, #[\SensitiveParameter] string $secret): bool
    {
        if ($secret === '') {
            return false;
        }
        $time = null;
        $signatures = [];
        foreach (explode(',', $notification->header('stripe-signature')) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && preg_match('/^\d{1,12}$/', $value) === 1) {
                $time = (int) $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($time === null || abs($this->clock->now()->getTimestamp() - $time) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', "{$time}.{$notification->body}", $secret);

        return array_any($signatures, static fn(#[\SensitiveParameter] string $signature): bool => hash_equals($expected, $signature));
    }

    /**
     * Runs a call to Stripe, reporting its absence as the extension API does.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     *
     * @throws ProviderUnavailable
     * @throws StripeRefused
     */
    private function call(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (PaymentProviderUnavailable $e) {
            throw new ProviderUnavailable('Stripe', $e->detail, $e);
        }
    }

    /**
     * @param array<mixed> $session
     */
    private static function intentId(array $session): ?string
    {
        $intent = $session['payment_intent'] ?? null;
        $id = \is_array($intent) ? self::text($intent, 'id') : (\is_string($intent) ? $intent : '');

        return $id === '' ? null : $id;
    }

    private static function key(Settings $settings): string
    {
        return trim($settings->text('secret_key'));
    }

    private static function webhookSecret(Settings $settings): string
    {
        return trim($settings->text('webhook_secret'));
    }

    private static function isTestKey(string $key): bool
    {
        return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'rk_test_');
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
