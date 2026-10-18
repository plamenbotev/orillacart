<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\HandlesNotifications;
use OrillaCart\Extension\Payment\HandlesReturns;
use OrillaCart\Extension\Payment\InvalidNotification;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Payment\Domain\Event\PaymentCancelled;
use OrillaCart\Payment\Domain\Event\PaymentCompleted;
use OrillaCart\Payment\Domain\Event\PaymentFailed;
use OrillaCart\Payment\Domain\Event\PaymentHeld;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentNotFound;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * How payments at providers end, whichever gateway took them: the customer coming back, and the provider's
 * notifications for customers who never did. Each payment settles once; what arrives after changes nothing.
 * A completed payment whose amount or currency is not the order's is held for the shop to look at.
 */
final readonly class PaymentOutcomes
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentMethodRepository $methods,
        private Gateways $gateways,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private Refunds $refunds,
    ) {}

    /**
     * The customer came back from the provider, paid or not.
     *
     * @param array<string, string> $query what the provider added to the address
     *
     * @return string where to send the customer: the order's receipt
     *
     * @throws PaymentNotFound for a return that names no payment of the gateway
     */
    public function returned(string $gatewayId, array $query, bool $cancelled): string
    {
        try {
            $gateway = $this->gateways->get($gatewayId);
        } catch (PaymentMethodNotFound) {
            throw new PaymentNotFound();
        }
        $return = new PaymentReturn($query, $cancelled);
        $reference = $gateway instanceof HandlesReturns ? $gateway->reference($return) : null;
        $payment = $reference === null ? null : $this->payments->findByReference($gatewayId, $reference);
        if ($payment === null || !$gateway instanceof HandlesReturns || $reference === null) {
            throw new PaymentNotFound();
        }
        if (!$payment->isSettled()) {
            $this->settle($payment, $gateway->returned($reference, $return, $this->settings($gatewayId)));
        }

        return $payment->receiptUrl();
    }

    /**
     * A notification from the provider. Notifications about payments the shop does not know are ignored.
     *
     * @throws PaymentMethodNotFound      for a gateway without notifications
     * @throws PaymentRuleViolation       for a notification the provider did not send
     * @throws PaymentProviderUnavailable when the provider could not confirm it; it sends it again
     */
    public function notified(string $gatewayId, Notification $notification): void
    {
        $gateway = $this->gateways->get($gatewayId);
        if (!$gateway instanceof HandlesNotifications) {
            throw new PaymentMethodNotFound($gatewayId);
        }
        try {
            $outcome = $gateway->notified($notification, $this->settings($gatewayId));
        } catch (InvalidNotification $e) {
            throw new PaymentRuleViolation('The notification is not from the payment provider.', 'orillacart_invalid_notification', $e);
        } catch (ProviderUnavailable $e) {
            throw new PaymentProviderUnavailable($e->provider, $e->getMessage(), $e);
        }
        if ($outcome === null) {
            return;
        }
        $payment = $outcome->reference === '' ? null : $this->payments->findByReference($gatewayId, $outcome->reference);
        if ($payment === null && $outcome->transactionId !== null && $outcome->status === PaymentOutcome::REFUNDED) {
            $payment = $this->payments->findByTransaction($gatewayId, $outcome->transactionId);
        }
        if ($payment === null) {
            return;
        }
        if ($outcome->status === PaymentOutcome::REFUNDED) {
            $this->refunds->notified($payment, $outcome);

            return;
        }
        $this->settle($payment, $outcome);
    }

    private function settle(Payment $payment, PaymentOutcome $outcome): void
    {
        $now = $this->clock->now();
        $event = match ($outcome->status) {
            PaymentOutcome::COMPLETED => $this->paysInFull($payment, $outcome)
                ? ($payment->complete($outcome->transactionId, $now) ? new PaymentCompleted($payment->orderId(), $payment->id(), $payment->gateway(), $outcome->transactionId ?? '') : null)
                : $this->hold($payment, $outcome),
            PaymentOutcome::HELD => $this->hold($payment, $outcome),
            PaymentOutcome::FAILED => $payment->fail($outcome->transactionId, $now) ? new PaymentFailed($payment->orderId(), $payment->id(), $payment->gateway(), $outcome->transactionId ?? '') : null,
            PaymentOutcome::CANCELLED => $payment->cancel($now) ? new PaymentCancelled($payment->orderId(), $payment->id(), $payment->gateway()) : null,
            default => null,
        };
        $this->payments->save($payment);
        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }

    /** The provider holds the money, or it does not match the order: the shop has to look before it ships. */
    private function hold(Payment $payment, PaymentOutcome $outcome): ?PaymentHeld
    {
        $marker = $outcome->transactionId ?? $outcome->reference;
        if ($payment->isSettled() || $payment->transactionId() === $marker) {
            return null;
        }
        $payment->hold($marker);

        return new PaymentHeld($payment->orderId(), $payment->id(), $payment->gateway(), $marker);
    }

    /** Whether what the provider says was paid is the payment's amount; providers that do not say are trusted. */
    private function paysInFull(Payment $payment, PaymentOutcome $outcome): bool
    {
        if ($outcome->amount === null) {
            return true;
        }
        $paid = preg_match('/^\d{1,11}(\.\d{1,4})?$/', $outcome->amount) === 1 ? Money::of($outcome->amount) : Money::zero();
        if (($outcome->currency === null || $outcome->currency === $payment->currency()) && $paid->equals($payment->amount())) {
            return true;
        }
        $this->logger->warning('{gateway} paid {paid} {paidCurrency} for payment {payment} of {amount} {currency}; the order waits for the shop.', [
            'gateway' => $payment->gateway(), 'paid' => $outcome->amount, 'paidCurrency' => $outcome->currency ?? $payment->currency(),
            'payment' => $payment->id(), 'amount' => (string) $payment->amount(), 'currency' => $payment->currency(),
        ]);

        return false;
    }

    private function settings(string $gatewayId): Settings
    {
        return new Settings($this->methods->findByGateway($gatewayId)?->settings() ?? []);
    }
}
