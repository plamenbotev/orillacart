<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Extension;

use OrillaCart\Extension\Payment\HandlesNotifications;
use OrillaCart\Extension\Payment\HandlesReturns;
use OrillaCart\Extension\Payment\InvalidNotification;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Settings;

/**
 * A provider's gateway as an add-on writes it, using only the extension API: the customer pays at
 * pay.test, comes back with ?ref=…, and the provider signs its notifications with the shop's key.
 */
final readonly class TestPayGateway implements PaymentGateway, HandlesReturns, HandlesNotifications
{
    public const string ID = 'test_pay';

    public function id(): string
    {
        return self::ID;
    }

    public function defaultName(): string
    {
        return 'Test Pay';
    }

    public function settings(): array
    {
        return [
            new Setting('api_key', 'API key', Setting::SECRET, required: true),
            new Setting('mode', 'Mode', Setting::CHOICE, choices: ['test' => 'Test', 'live' => 'Live']),
            new Setting('fee', 'Fee', Setting::NUMBER),
        ];
    }

    public function isReady(Settings $settings, string $currency): bool
    {
        return $settings->text('api_key') !== '';
    }

    public function start(PaymentSession $session, Settings $settings): PaymentStart
    {
        $reference = "REF-{$session->paymentId}";

        return PaymentStart::redirect('https://pay.test/?ref=' . $reference . '&back=' . rawurlencode($session->returnUrl), $reference);
    }

    public function reference(PaymentReturn $return): ?string
    {
        return $return->query['ref'] ?? null;
    }

    public function returned(string $reference, PaymentReturn $return, Settings $settings): PaymentOutcome
    {
        return $return->cancelled ? PaymentOutcome::cancelled($reference) : PaymentOutcome::completed($reference, 'TX-' . $reference);
    }

    public function notified(Notification $notification, Settings $settings): ?PaymentOutcome
    {
        if (!hash_equals(hash_hmac('sha256', $notification->body, $settings->text('api_key')), $notification->header('X-Test-Signature'))) {
            throw new InvalidNotification('Not signed with the shop\'s key.');
        }
        $event = json_decode($notification->body, true);
        if (!\is_array($event) || !\is_string($event['ref'] ?? null)) {
            return null;
        }
        $amount = \is_string($event['amount'] ?? null) ? $event['amount'] : null;

        return ($event['status'] ?? '') === 'paid'
            ? PaymentOutcome::completed($event['ref'], 'TX-' . $event['ref'], $amount, 'EUR')
            : PaymentOutcome::failed($event['ref']);
    }
}
