<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\PayPal;

use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalCapture;
use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Domain\Event\PaymentCancelled;
use OrillaCart\Payment\Domain\Event\PaymentCompleted;
use OrillaCart\Payment\Domain\Event\PaymentFailed;
use OrillaCart\Payment\Domain\Event\PaymentHeld;
use OrillaCart\Payment\Domain\Payment;
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
 * How PayPal payments end: the customer coming back (approved or cancelled), and PayPal's webhooks for
 * customers who never came back. Both paths settle a payment once; the second one to arrive changes nothing.
 */
final readonly class PayPalPayments
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentMethodRepository $methods,
        private PayPalApi $api,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * The customer approved the PayPal order and came back: capture the money.
     *
     * @param string $token the PayPal order id PayPal adds to the return address
     *
     * When PayPal cannot be reached, the customer still sees the receipt and the webhook settles the payment.
     *
     * @return string where to send the customer: the order's receipt
     *
     * @throws PaymentNotFound
     */
    public function approve(string $token): string
    {
        $payment = $this->payment($token);
        if (!$payment->isSettled()) {
            try {
                $this->settle($payment, $this->api->captureOrder($this->account(), $token));
            } catch (PaymentProviderUnavailable $e) {
                $this->logger->warning('Payment {payment} could not be captured when the customer came back; the webhook settles it. {reason}', ['payment' => $payment->id(), 'reason' => $e->detail]);
            }
        }

        return $payment->receiptUrl();
    }

    /**
     * The customer gave up at PayPal.
     *
     * @return string where to send the customer: the order's receipt
     *
     * @throws PaymentNotFound
     */
    public function cancel(string $token): string
    {
        $payment = $this->payment($token);
        if ($payment->cancel($this->clock->now())) {
            $this->payments->save($payment);
            $this->events->dispatch(new PaymentCancelled($payment->orderId(), $payment->id(), $payment->gateway()));
        }

        return $payment->receiptUrl();
    }

    /**
     * A webhook call. Only calls PayPal signed for the shop's webhook are read; events about payments the
     * shop does not know are ignored.
     *
     * @throws PaymentRuleViolation       for a call PayPal did not sign
     * @throws PaymentProviderUnavailable
     */
    public function notify(PayPalNotification $notification): void
    {
        $account = $this->account();
        if ($account->webhookId === '' || !$this->api->verifyNotification($account, $notification)) {
            throw new PaymentRuleViolation('The notification is not signed by PayPal for this shop.', 'orillacart_invalid_notification');
        }
        $event = json_decode($notification->body, true);
        $type = \is_array($event) && \is_string($event['event_type'] ?? null) ? $event['event_type'] : '';
        $resource = \is_array($event) && \is_array($event['resource'] ?? null) ? $event['resource'] : [];

        if ($type === 'CHECKOUT.ORDER.APPROVED') {
            $orderId = self::text($resource, 'id');
            $payment = $orderId === '' ? null : $this->payments->findByReference(PayPalGateway::ID, $orderId);
            if ($payment !== null && !$payment->isSettled()) {
                $this->settle($payment, $this->api->captureOrder($account, $orderId));
            }

            return;
        }
        if (str_starts_with($type, 'PAYMENT.CAPTURE.')) {
            $supplementary = \is_array($resource['supplementary_data'] ?? null) ? $resource['supplementary_data'] : [];
            $related = \is_array($supplementary['related_ids'] ?? null) ? $supplementary['related_ids'] : [];
            $orderId = self::text($related, 'order_id');
            $payment = $orderId === '' ? null : $this->payments->findByReference(PayPalGateway::ID, $orderId);
            $amount = \is_array($resource['amount'] ?? null) ? $resource['amount'] : [];
            if ($payment !== null) {
                $this->settle($payment, new PayPalCapture(
                    self::text($resource, 'id'),
                    self::text($resource, 'status'),
                    self::text($amount, 'value'),
                    self::text($amount, 'currency_code'),
                    $orderId,
                ));
            }
        }
    }

    private function settle(Payment $payment, PayPalCapture $capture): void
    {
        $now = $this->clock->now();
        $event = match ($capture->status) {
            'COMPLETED' => $this->paysInFull($payment, $capture)
                ? ($payment->complete($capture->id, $now) ? new PaymentCompleted($payment->orderId(), $payment->id(), $payment->gateway(), $capture->id) : null)
                : $this->hold($payment, $capture),
            'PENDING' => $this->hold($payment, $capture),
            'DECLINED', 'FAILED' => $payment->fail($capture->id, $now) ? new PaymentFailed($payment->orderId(), $payment->id(), $payment->gateway(), $capture->id) : null,
            default => null,
        };
        $this->payments->save($payment);
        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }

    /** PayPal holds the money, or it does not match the order: the shop has to look before it ships. */
    private function hold(Payment $payment, PayPalCapture $capture): ?PaymentHeld
    {
        if ($payment->isSettled() || $payment->transactionId() === $capture->id) {
            return null;
        }
        $payment->hold($capture->id);

        return new PaymentHeld($payment->orderId(), $payment->id(), $payment->gateway(), $capture->id);
    }

    private function paysInFull(Payment $payment, PayPalCapture $capture): bool
    {
        $paid = preg_match('/^\d{1,11}(\.\d{1,4})?$/', $capture->amount) === 1 ? Money::of($capture->amount) : Money::zero();
        if ($capture->currency === $payment->currency() && $paid->equals($payment->amount())) {
            return true;
        }
        $this->logger->warning('PayPal capture {capture} of payment {payment} is {paid} {paidCurrency}, the payment is {amount} {currency}; the order waits for the shop.', [
            'capture' => $capture->id, 'payment' => $payment->id(), 'paid' => $capture->amount, 'paidCurrency' => $capture->currency,
            'amount' => (string) $payment->amount(), 'currency' => $payment->currency(),
        ]);

        return false;
    }

    private function payment(string $token): Payment
    {
        return ($token === '' ? null : $this->payments->findByReference(PayPalGateway::ID, $token)) ?? throw new PaymentNotFound();
    }

    private function account(): PayPalAccount
    {
        return PayPalGateway::account($this->methods->findByGateway(PayPalGateway::ID)?->settings() ?? []);
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
