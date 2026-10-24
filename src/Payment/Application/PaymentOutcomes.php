<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\ChecksPayments;
use OrillaCart\Extension\Payment\HandlesNotifications;
use OrillaCart\Extension\Payment\HandlesReturns;
use OrillaCart\Extension\Payment\InvalidNotification;
use OrillaCart\Extension\Payment\NamesNotifiedEvents;
use OrillaCart\Extension\Payment\NamesNotifiedPayments;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Payment\Domain\Event\PaymentCancelled;
use OrillaCart\Payment\Domain\Event\PaymentCompleted;
use OrillaCart\Payment\Domain\Event\PaymentFailed;
use OrillaCart\Payment\Domain\Event\PaymentHeld;
use OrillaCart\Payment\Domain\Event\PaymentInQuestion;
use OrillaCart\Payment\Domain\NotifiedEvent;
use OrillaCart\Payment\Domain\NotifiedEvents;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentNotFound;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * How payments at providers end, whichever gateway took them: the customer coming back, and the provider's
 * notifications for customers who never did. Each payment settles once; what arrives after changes nothing. The
 * provider is asked first, with nothing locked; the payment then settles in one transaction with its row locked,
 * so the customer's return and the provider's notification arriving together do not both settle it.
 * A completed payment whose amount or currency is not the order's is held for the shop to look at.
 */
final readonly class PaymentOutcomes
{
    /** What the return and cancel addresses' signatures are for. */
    public const string RETURN_SIGNATURE = 'payment-return';

    /** A payment is checked once nothing final came for this long */
    private const string CHECK_AFTER = 'PT15M';
    /** and for this long after it started: a provider forgets sessions nobody finished. */
    private const string CHECK_FOR = 'P30D';

    public function __construct(
        private PaymentRepository $payments,
        private PaymentMethodRepository $methods,
        private Gateways $gateways,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private Refunds $refunds,
        private Transactions $transactions,
        private OrderLinks $links,
        private Signatures $signatures,
        private NotifiedEvents $handled,
    ) {}

    /**
     * The customer came back from the provider, paid or not. The address carries the payment's id, signed when
     * the payment started, so a made-up address settles nothing.
     *
     * @param array<string, string> $query what the provider added to the address, and the shop's payment and sig
     *
     * @return string where to send the customer: the order's receipt
     *
     * @throws PaymentNotFound for a return that names no payment of the gateway, or not the one it was signed for
     */
    public function returned(string $gatewayId, array $query, bool $cancelled): string
    {
        $id = $query['payment'] ?? '';
        if (preg_match('/^\d{1,18}$/', $id) !== 1 || !$this->signatures->verify(self::RETURN_SIGNATURE, "{$gatewayId}:{$id}", $query['sig'] ?? '')) {
            throw new PaymentNotFound();
        }
        try {
            $gateway = $this->gateways->get($gatewayId);
        } catch (PaymentMethodNotFound) {
            throw new PaymentNotFound();
        }
        $return = new PaymentReturn($query, $cancelled);
        $reference = $gateway instanceof HandlesReturns ? $gateway->reference($return) : null;
        $payment = $reference === null ? null : $this->payments->findByReference($gatewayId, $reference);
        if ($payment === null || !$gateway instanceof HandlesReturns || $reference === null || $payment->id() !== (int) $id) {
            throw new PaymentNotFound();
        }
        if (!$payment->isSettled() && !PaymentAccount::left($payment, $gateway, $this->settings($gatewayId))) {
            try {
                $outcome = $gateway->returned($reference, $return, $this->settings($gatewayId));
            } catch (\Throwable $e) {
                // The provider did not answer, or the gateway broke: the customer sees the receipt, and the
                // provider's notification settles the payment later.
                $this->logger->warning('Payment {payment} could not be looked up at {gateway} when the customer came back: {message}', ['payment' => $payment->id(), 'gateway' => $gatewayId, 'message' => $e->getMessage(), 'exception' => $e]);

                return $this->links->receiptUrl($payment->orderId());
            }
            // A customer who chose to go back without paying may still pay, at the provider again or another way:
            // the payment ends, the order waits, and the time the shop gives unpaid orders cancels it.
            $this->settle($payment, $outcome, givenUp: $cancelled);
        }

        return $this->links->receiptUrl($payment->orderId());
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
        // The same event once: a provider retrying, or a notification replayed, changes nothing again.
        $eventId = $gateway instanceof NamesNotifiedEvents ? $gateway->eventId($notification) : null;
        if ($eventId !== null && $this->handled->wasHandled($gatewayId, $eventId)) {
            return;
        }
        if ($gateway instanceof NamesNotifiedPayments) {
            $reference = $gateway->paymentReference($notification);
            if ($reference !== null && $this->payments->findByReference($gatewayId, $reference) === null) {
                // Another site's payment, or one deleted since: not this shop's to act on.
                return;
            }
        }
        try {
            $outcome = $gateway->notified($notification, $this->settings($gatewayId));
        } catch (InvalidNotification $e) {
            throw new PaymentRuleViolation('The notification is not from the payment provider.', 'orillacart_invalid_notification', $e);
        } catch (ProviderUnavailable $e) {
            throw new PaymentProviderUnavailable($e->provider, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            // A gateway that broke: the provider sends the notification again later.
            $this->logger->error('Payment method {gateway} failed on a notification: {message}', ['gateway' => $gatewayId, 'message' => $e->getMessage(), 'exception' => $e]);

            throw new PaymentProviderUnavailable($this->methods->findByGateway($gatewayId)?->name() ?? $gateway->defaultName(), $e->getMessage(), $e);
        }
        $this->act($gatewayId, $gateway, $outcome);
        // Only once acted on: an event that broke on the way is handled when the provider sends it again.
        if ($eventId !== null) {
            $this->handled->remember(new NotifiedEvent($gatewayId, $eventId, $this->clock->now()));
        }
    }

    private function act(string $gatewayId, PaymentGateway&HandlesNotifications $gateway, ?PaymentOutcome $outcome): void
    {
        if ($outcome === null) {
            return;
        }
        $payment = $outcome->reference === '' ? null : $this->payments->findByReference($gatewayId, $outcome->reference);
        if ($payment === null && $outcome->transactionId !== null && \in_array($outcome->status, [PaymentOutcome::REFUNDED, PaymentOutcome::DISPUTED], true)) {
            $payment = $this->payments->findByTransaction($gatewayId, $outcome->transactionId);
        }
        if ($payment === null || PaymentAccount::left($payment, $gateway, $this->settings($gatewayId))) {
            return;
        }
        if ($outcome->status === PaymentOutcome::DISPUTED) {
            $this->events->dispatch(new PaymentInQuestion($payment->orderId(), $gatewayId, $outcome->reason ?? 'The provider says the payment is in question.'));

            return;
        }
        if ($outcome->status === PaymentOutcome::REFUNDED) {
            $this->refunds->notified($payment, $outcome);

            return;
        }
        $this->settle($payment, $outcome);
    }

    /**
     * Asks the providers how payments stand that the shop heard nothing final of for a quarter of an hour: one
     * the provider holds for review, one whose customer never came back while no notification came. Settles
     * what changed, as a return or a notification would.
     *
     * @return int how many were asked about
     */
    public function reconcile(int $limit = 50): int
    {
        $now = $this->clock->now();
        $asked = 0;
        foreach ($this->payments->openBetween($now->sub(new \DateInterval(self::CHECK_FOR)), $now->sub(new \DateInterval(self::CHECK_AFTER)), $limit) as $payment) {
            $reference = $payment->reference();
            try {
                $gateway = $this->gateways->get($payment->gateway());
            } catch (PaymentMethodNotFound) {
                continue;
            }
            if (!$gateway instanceof ChecksPayments || $reference === null) {
                continue;
            }
            $payment->checked($now);
            $this->payments->save($payment);
            // Made in another account or mode (PayPal's sandbox, say) than the settings point at now: asking there
            // would read it as unknown and cancel its order.
            if (PaymentAccount::left($payment, $gateway, $this->settings($payment->gateway()))) {
                continue;
            }
            ++$asked;
            try {
                $outcome = $gateway->check($reference, $payment->transactionId(), $this->settings($payment->gateway()));
            } catch (\Throwable $e) {
                $this->logger->warning('Payment {payment} could not be checked at {gateway}: {message}', ['payment' => $payment->id(), 'gateway' => $payment->gateway(), 'message' => $e->getMessage()]);
                continue;
            }
            $this->settle($payment, $outcome);
        }

        return $asked;
    }

    private function settle(Payment $found, PaymentOutcome $outcome, bool $givenUp = false): void
    {
        $this->transactions->run(function () use ($found, $outcome, $givenUp): void {
            $payment = $this->payments->find($found->id(), true);
            if ($payment !== null) {
                $this->settleLocked($payment, $outcome, $givenUp);
            }
        });
    }

    /**
     * Settles the payment, and tells the order. A payment the order replaced since (paid another way, or started
     * again) only tells it of money: its failing, being given up or held moves nothing.
     */
    private function settleLocked(Payment $payment, PaymentOutcome $outcome, bool $givenUp): void
    {
        $now = $this->clock->now();
        $replaced = array_any($this->payments->ofOrder($payment->orderId()), static fn(Payment $other): bool => $other->id() > $payment->id());
        $event = match ($outcome->status) {
            PaymentOutcome::COMPLETED => $this->paysInFull($payment, $outcome)
                ? ($payment->complete($outcome->transactionId, $now) ? new PaymentCompleted($payment->orderId(), $payment->id(), $payment->gateway(), $outcome->transactionId ?? '', (string) $payment->amount()) : null)
                : $this->hold($payment, $outcome),
            PaymentOutcome::HELD => $this->hold($payment, $outcome),
            PaymentOutcome::FAILED => $payment->fail($outcome->transactionId, $now) ? new PaymentFailed($payment->orderId(), $payment->id(), $payment->gateway(), $outcome->transactionId ?? '') : null,
            PaymentOutcome::CANCELLED => $payment->cancel($now) ? new PaymentCancelled($payment->orderId(), $payment->id(), $payment->gateway()) : null,
            default => null,
        };
        $this->payments->save($payment);
        if ($event instanceof PaymentCompleted || ($event !== null && !$replaced && !($givenUp && $event instanceof PaymentCancelled))) {
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

    /** Forgets the providers' events handled more than a month ago, daily: longer than any provider retries. */
    public function forgetOldEvents(): void
    {
        $this->handled->forgetBefore($this->clock->now()->modify('-30 days'));
    }
}
