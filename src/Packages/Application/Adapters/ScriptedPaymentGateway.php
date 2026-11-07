<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Payment\ChecksPayments;
use OrillaCart\Extension\Payment\HandlesNotifications;
use OrillaCart\Extension\Payment\HandlesReturns;
use OrillaCart\Extension\Payment\InvalidNotification;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Settings;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/**
 * A `payment_gateway "id" { … }` declaration as the shop's payment contract. Its functions: `start(session,
 * settings)` (required), `not_ready(settings, currency, decimals)`, `reference(return)`, `returned(reference,
 * return, settings)`, `notified(notification, settings)`, `check(reference, transaction_id, settings)`; with a
 * `refund` function it is a ScriptedRefundingGateway.
 */
class ScriptedPaymentGateway extends ScriptedProvider implements PaymentGateway, HandlesReturns, HandlesNotifications, ChecksPayments
{
    public function defaultName(): string
    {
        return $this->title();
    }

    public function notReady(Settings $settings, string $currency, int $decimals): ?string
    {
        if (!$this->has('not_ready')) {
            return $this->missingSetting($settings);
        }
        try {
            $answer = $this->call('not_ready', [self::values($settings), $currency, $decimals]);
        } catch (HandlerFailed $e) {
            return $e->getMessage();
        }

        return $answer === null || $answer === false ? null : Values::text($answer);
    }

    public function start(PaymentSession $session, Settings $settings): PaymentStart
    {
        try {
            $start = $this->expect($this->call('start', [Values::session($session), self::values($settings)]), PaymentStart::class, 'start');
        } catch (HandlerFailed $e) {
            // Refused: the provider will not take this payment; the shop tells the customer as it does for any gateway.
            throw new \OrillaCart\Extension\ProviderUnavailable($this->title(), $e->getMessage(), $e);
        }
        \assert($start instanceof PaymentStart);

        return $start;
    }

    public function reference(PaymentReturn $return): ?string
    {
        if (!$this->has('reference')) {
            $reference = $return->query['reference'] ?? null;

            return \is_string($reference) && $reference !== '' ? $reference : null;
        }
        try {
            $answer = $this->call('reference', [Values::paymentReturn($return)]);
        } catch (HandlerFailed) {
            return null;
        }

        return $answer === null ? null : Values::text($answer);
    }

    public function returned(string $reference, PaymentReturn $return, Settings $settings): PaymentOutcome
    {
        if (!$this->has('returned')) {
            return PaymentOutcome::pending($reference);
        }
        try {
            $outcome = $this->expect($this->call('returned', [$reference, Values::paymentReturn($return), self::values($settings)]), PaymentOutcome::class, 'returned');
        } catch (HandlerFailed) {
            return PaymentOutcome::failed($reference);
        }
        \assert($outcome instanceof PaymentOutcome);

        return $outcome;
    }

    public function notified(Notification $notification, Settings $settings): ?PaymentOutcome
    {
        if (!$this->has('notified')) {
            return null;
        }
        try {
            $answer = $this->call('notified', [Values::notification($notification), self::values($settings)]);
        } catch (HandlerFailed $e) {
            throw new InvalidNotification($e->getMessage(), 0, $e);
        }
        if ($answer === null) {
            return null;
        }
        $outcome = $this->expect($answer, PaymentOutcome::class, 'notified');
        \assert($outcome instanceof PaymentOutcome);

        return $outcome;
    }

    public function check(string $reference, ?string $transactionId, Settings $settings): PaymentOutcome
    {
        if (!$this->has('check')) {
            return PaymentOutcome::pending($reference);
        }
        try {
            $outcome = $this->expect($this->call('check', [$reference, $transactionId, self::values($settings)]), PaymentOutcome::class, 'check');
        } catch (HandlerFailed) {
            return PaymentOutcome::pending($reference);
        }
        \assert($outcome instanceof PaymentOutcome);

        return $outcome;
    }
}
