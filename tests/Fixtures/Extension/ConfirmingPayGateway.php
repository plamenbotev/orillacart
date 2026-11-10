<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Extension;

use OrillaCart\Extension\Payment\ConfirmsNotifications;
use OrillaCart\Extension\Payment\HandlesReturns;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Settings;

/**
 * A gateway as a script may write it, believing what a notification says; the shop confirms with check(),
 * which answers as the provider would, from what the test says it holds.
 */
final class ConfirmingPayGateway implements PaymentGateway, HandlesReturns, ConfirmsNotifications
{
    public const string ID = 'confirm_pay';

    /** @var array<string, string> what the provider says of each payment: paid, or anything else for open */
    public array $provider = [];
    /** @var list<string> the notifications the gateway read */
    public array $read = [];
    /** @var list<string> the references it was asked to check */
    public array $checked = [];

    public function id(): string
    {
        return self::ID;
    }

    public function defaultName(): string
    {
        return 'Confirm Pay';
    }

    public function settings(): array
    {
        return [];
    }

    public function notReady(Settings $settings, string $currency, int $decimals): ?string
    {
        return null;
    }

    public function start(PaymentSession $session, Settings $settings): PaymentStart
    {
        return PaymentStart::redirect('https://pay.test/?ref=REF-' . $session->paymentId, "REF-{$session->paymentId}");
    }

    public function reference(PaymentReturn $return): ?string
    {
        return $return->query['ref'] ?? null;
    }

    public function returned(string $reference, PaymentReturn $return, Settings $settings): PaymentOutcome
    {
        return PaymentOutcome::pending($reference);
    }

    public function notified(Notification $notification, Settings $settings): ?PaymentOutcome
    {
        $this->read[] = $notification->body;
        $event = json_decode($notification->body, true);

        return \is_array($event) && \is_string($event['ref'] ?? null) ? PaymentOutcome::completed($event['ref'], 'TX-' . $event['ref'], '8.00', 'EUR') : null;
    }

    public function check(string $reference, ?string $transactionId, Settings $settings): PaymentOutcome
    {
        $this->checked[] = $reference;

        return ($this->provider[$reference] ?? '') === 'paid' ? PaymentOutcome::completed($reference, 'TX-' . $reference, '8.00', 'EUR') : PaymentOutcome::pending($reference);
    }
}
