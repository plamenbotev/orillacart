<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\RefundRefused;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\Payment\RefundsPayments;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\Dto\RefundOrder;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\OrderRefundsView;
use OrillaCart\Payment\Application\Dto\RefundView;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Payment\Domain\PaymentStatus;
use OrillaCart\Payment\Domain\Refund;
use OrillaCart\Payment\Domain\RefundMethod;
use OrillaCart\Payment\Domain\RefundRepository;
use OrillaCart\Payment\Domain\RefundStatus;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Persistence\Transactions;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives money back for orders: through the gateway that took the payment when it can (RefundsPayments), or as
 * a record of a refund the shop made outside. The order records the refund only once the money went: a
 * provider's refund is saved as pending first, so one the provider took while the shop timed out is tried again
 * under the same id, never sent twice. Refunds made at the provider's own site arrive as notifications.
 */
final readonly class Refunds
{
    public function __construct(
        private RefundRepository $refunds,
        private PaymentRepository $payments,
        private PaymentMethodRepository $methods,
        private Gateways $gateways,
        private OrderService $orders,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private Transactions $transactions,
    ) {}

    /**
     * @throws OrderNotFound
     */
    public function forOrder(int $orderId): OrderRefundsView
    {
        $order = $this->orders->order($orderId);
        $payment = $this->refundablePayment($orderId);

        return new OrderRefundsView(
            (string) Money::of($order->total)->subtract(Money::of($order->refundedTotal))->atLeastZero(),
            $order->currency,
            \in_array($order->status, ['processing', 'shipped', 'completed'], true),
            $payment === null ? null : $this->name($payment->gateway()),
            array_map(RefundView::fromRefund(...), $this->refunds->ofOrder($orderId)),
        );
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation         for a refund the order does not allow
     * @throws PaymentRuleViolation       when the provider refused it, or another refund is still pending
     *                                    or when the provider did not answer, and the refund waits to be tried again
     */
    public function refund(int $orderId, MakeRefund $command, ?int $userId): OrderRefundsView
    {
        $order = $this->orders->order($orderId);
        $this->orders->checkRefund($orderId, $command->forOrder());
        foreach ($this->refunds->ofOrder($orderId) as $earlier) {
            if ($earlier->status() === RefundStatus::Pending) {
                throw new PaymentRuleViolation('A refund of this order is still waiting for the provider: try it again or give it up first.', 'orillacart_refund_pending');
            }
        }
        $payment = $command->outside ? null : $this->refundablePayment($orderId);
        $refund = new Refund(
            $orderId,
            $payment?->id(),
            $payment?->gateway() ?? $order->paymentGateway,
            $payment === null ? RefundMethod::Manual : RefundMethod::Provider,
            Money::of($command->amount),
            $order->currency,
            self::quantities($command->forOrder()),
            $command->shipping,
            $command->restock,
            trim($command->reason),
            $userId,
            $this->clock->now(),
        );
        if ($payment === null) {
            $this->transactions->run(function () use ($refund, $orderId, $command, $userId): void {
                $this->refunds->save($refund);
                $this->orders->refund($orderId, $command->forOrder(), 'outside the shop', $userId);
            });
        } else {
            // Saved before the provider is asked: an answer lost on the way leaves it pending, never forgotten.
            $this->refunds->save($refund);
            $this->send($refund, $payment, $userId);
        }

        return $this->forOrder($orderId);
    }

    /**
     * Asks the provider again about a refund it did not answer, under the same id.
     *
     * @throws PaymentRuleViolation
     */
    public function retry(int $refundId, ?int $userId): OrderRefundsView
    {
        $refund = $this->pending($refundId);
        $payment = $refund->paymentId() === null ? null : $this->payments->find($refund->paymentId());
        if ($payment === null) {
            throw new PaymentRuleViolation('The payment this refund is for is gone.', 'orillacart_refund_payment_missing');
        }
        $this->send($refund, $payment, $userId);

        return $this->forOrder($refund->orderId());
    }

    /**
     * Gives up a refund the provider never answered, after the shop checked at the provider that it did not
     * go through. The order is not changed.
     *
     * @throws PaymentRuleViolation
     */
    public function giveUp(int $refundId): OrderRefundsView
    {
        $refund = $this->pending($refundId);
        $refund->fail('Given up by the shop: the provider never confirmed it.', $this->clock->now());
        $this->refunds->save($refund);

        return $this->forOrder($refund->orderId());
    }

    /**
     * A refund the provider told of. One the shop sent is recognised by its id, or completes a refund of the
     * same amount still waiting for the provider's answer; any other was made at the provider's own site and is
     * recorded as an amount alone.
     */
    public function notified(Payment $payment, PaymentOutcome $outcome): void
    {
        $refundId = $outcome->refundId ?? '';
        if ($refundId === '' || $this->refunds->findByProviderReference($payment->gateway(), $refundId) !== null) {
            return;
        }
        $amount = preg_match('/^\d{1,11}(\.\d{1,4})?$/', $outcome->amount ?? '') === 1 ? Money::of($outcome->amount ?? '0') : Money::zero();
        foreach ($this->refunds->ofOrder($payment->orderId()) as $refund) {
            if ($refund->status() === RefundStatus::Pending && $refund->paymentId() === $payment->id() && $refund->amount()->equals($amount)) {
                $this->completed($refund, $refundId, false, null);

                return;
            }
        }

        try {
            $order = $this->orders->order($payment->orderId());
            $left = Money::of($order->total)->subtract(Money::of($order->refundedTotal));
            if ($amount->isZero() || $amount->compare($left) > 0) {
                $this->logger->warning('{gateway} refunded {amount} of order {order}, which has {left} left to refund; record it by hand.', ['gateway' => $payment->gateway(), 'amount' => (string) $amount, 'order' => $payment->orderId(), 'left' => (string) $left]);

                return;
            }
            $refund = new Refund($payment->orderId(), $payment->id(), $payment->gateway(), RefundMethod::External, $amount, $payment->currency(), [], false, false, '', null, $this->clock->now());
            $refund->refer($refundId);
            $command = new RefundOrder((string) $amount, reason: \sprintf('Made at %s.', $this->name($payment->gateway())));
            $this->orders->checkRefund($payment->orderId(), $command);
            $this->transactions->run(function () use ($refund, $payment, $command, $refundId): void {
                $this->refunds->save($refund);
                $this->orders->refund($payment->orderId(), $command, \sprintf('through %s (refund %s)', $this->name($payment->gateway()), $refundId), null);
            });
        } catch (OrderNotFound|OrderRuleViolation $e) {
            $this->logger->warning('{gateway} refunded {amount} of order {order}, which the shop could not record: {message}', ['gateway' => $payment->gateway(), 'amount' => (string) $amount, 'order' => $payment->orderId(), 'message' => $e->getMessage()]);
        }
    }

    private function send(Refund $refund, Payment $payment, ?int $userId): void
    {
        $gateway = $this->gateways->get($payment->gateway());
        if (!$gateway instanceof RefundsPayments) {
            throw new PaymentRuleViolation('This payment method cannot give money back; record a refund made outside the shop.', 'orillacart_refund_unsupported');
        }
        $order = $this->orders->order($refund->orderId());
        try {
            $result = $gateway->refund(new RefundRequest(
                $refund->idempotencyKey(),
                $refund->orderId(),
                $payment->reference(),
                $payment->transactionId(),
                $refund->amount()->toFixed(),
                $refund->currency(),
                $refund->reason(),
            ), new Settings($this->methods->findByGateway($payment->gateway())?->settings() ?? []));
        } catch (RefundRefused $e) {
            $refund->fail($e->getMessage(), $this->clock->now());
            $this->refunds->save($refund);

            throw new PaymentRuleViolation(\sprintf('%s refused the refund: %s', $this->name($payment->gateway()), $e->getMessage()), 'orillacart_refund_refused', $e);
        } catch (ProviderUnavailable $e) {
            $refund->unanswered($e->getMessage());
            $this->refunds->save($refund);
            $this->logger->warning('Refund {refund} of order {order}: {message}', ['refund' => $refund->id(), 'order' => $order->number, 'message' => $e->getMessage()]);

            throw new PaymentRuleViolation(\sprintf('%s did not answer, so the refund waits. Try it again: it is never sent twice.', $this->name($payment->gateway())), 'orillacart_refund_unanswered', $e);
        }
        $this->completed($refund, $result->reference, $result->pending, $userId);
    }

    /**
     * The refund row and the order learn of the money together, in one transaction.
     */
    private function completed(Refund $refund, string $reference, bool $providerPending, ?int $userId): void
    {
        $this->transactions->run(function () use ($refund, $reference, $providerPending, $userId): void {
            if (!$refund->complete($reference, $providerPending, $this->clock->now())) {
                return;
            }
            $this->refunds->save($refund);
            $command = new RefundOrder(
                $refund->amount()->toFixed(),
                array_map(static fn(int $line, int $quantity): RefundLine => new RefundLine($line, $quantity), array_keys($refund->quantities()), array_values($refund->quantities())),
                $refund->coversShipping(),
                $refund->restocks(),
                $refund->reason(),
            );
            try {
                $this->orders->refund($refund->orderId(), $command, \sprintf('through %s (refund %s)', $this->name((string) $refund->gateway()), $reference), $userId);
            } catch (OrderNotFound|OrderRuleViolation $e) {
                // The money went back: the order must learn of it, even if not item by item.
                $this->logger->error('Refund {refund} went through but order {order} could not record it: {message}', ['refund' => $refund->id(), 'order' => $refund->orderId(), 'message' => $e->getMessage()]);
            }
        });
    }

    private function pending(int $refundId): Refund
    {
        $refund = $this->refunds->find($refundId);
        if ($refund === null || $refund->status() !== RefundStatus::Pending) {
            throw new PaymentRuleViolation('Only a refund waiting for the provider can be tried again or given up.', 'orillacart_refund_not_pending');
        }

        return $refund;
    }

    /** The order's completed payment, when its gateway can refund it. */
    private function refundablePayment(int $orderId): ?Payment
    {
        $completed = array_filter($this->payments->ofOrder($orderId), static fn(Payment $payment): bool => $payment->status() === PaymentStatus::Completed);
        $payment = $completed === [] ? null : $completed[array_key_last($completed)];
        try {
            return $payment !== null && $this->gateways->get($payment->gateway()) instanceof RefundsPayments ? $payment : null;
        } catch (PaymentMethodNotFound) {
            return null;
        }
    }

    private function name(string $gateway): string
    {
        $method = $this->methods->findByGateway($gateway);
        if ($method !== null) {
            return $method->name();
        }
        try {
            return $this->gateways->get($gateway)->defaultName();
        } catch (PaymentMethodNotFound) {
            return $gateway;
        }
    }

    /**
     * @return array<int, int>
     */
    private static function quantities(RefundOrder $command): array
    {
        $quantities = [];
        foreach ($command->lines as $line) {
            $quantities[$line->line] = ($quantities[$line->line] ?? 0) + $line->quantity;
        }

        return $quantities;
    }
}
