<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\ChecksRefunds;
use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\RefundRefused;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\Payment\RefundsPayments;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\RefundedView;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\Dto\RefundOrder;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\OrderRefundsView;
use OrillaCart\Payment\Application\Dto\RefundView;
use OrillaCart\Payment\Domain\Event\PaymentInQuestion;
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
use Psr\EventDispatcher\EventDispatcherInterface;
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
        private GiftCardService $giftCards,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @throws OrderNotFound
     */
    public function forOrder(int $orderId): OrderRefundsView
    {
        $order = $this->orders->order($orderId);
        $payment = $this->refundablePayment($orderId);

        return new OrderRefundsView(
            $order->refundable,
            $order->currency,
            $order->paidAt !== null && $order->status !== 'refunded',
            $payment === null ? null : $this->name($payment->gateway()),
            array_map(RefundView::fromRefund(...), $this->refunds->ofOrder($orderId)),
        );
    }

    /**
     * @param (\Closure(int): void)|null $recorded what goes with the refund, by its id, in the same transaction with the
     *                                           order locked, before the provider is asked: a return marking itself
     *                                           refunding. What it throws undoes the refund.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation         for a refund the order does not allow
     * @throws PaymentRuleViolation       when the provider refused it, or another refund is still pending
     *                                    or when the provider did not answer, and the refund waits to be tried again
     */
    public function refund(int $orderId, MakeRefund $command, ?int $userId, ?\Closure $recorded = null): OrderRefundsView
    {
        // Checked and saved with the order locked: a second click, or a request tried again after a slow answer,
        // finds the first one pending and is refused, rather than both reaching the provider.
        [$refund, $payment, $credit] = $this->orders->whileLocked($orderId, function () use ($orderId, $command, $userId, $recorded): array {
            $order = $this->orders->order($orderId);
            $this->orders->checkRefund($orderId, $command->forOrder());
            foreach ($this->refunds->ofOrder($orderId) as $earlier) {
                if ($earlier->status() === RefundStatus::Pending) {
                    throw new PaymentRuleViolation('A refund of this order is still waiting for the provider: try it again or give it up first.', 'orillacart_refund_pending');
                }
                if ($earlier->status() === RefundStatus::Accepted) {
                    throw new PaymentRuleViolation('The provider is still sending the money of a refund of this order; refund more once it is done.', 'orillacart_refund_pending');
                }
            }
            $payment = $command->outside || $command->storeCredit ? null : $this->refundablePayment($orderId);
            if ($payment !== null && PaymentAccount::left($payment, $this->gateways->get($payment->gateway()), new Settings($this->methods->findByGateway($payment->gateway())?->settings() ?? []))) {
                throw new PaymentRuleViolation('The payment through %s was made in another of its accounts or modes (sandbox or live) than the one set up now: switch back to refund it, or refund it at the provider and record it here as refunded outside the shop.', 'orillacart_refund_other_account', values: [$this->name($payment->gateway())]);
            }
            if ($payment !== null) {
                $left = $this->leftOf($payment);
                if (Money::of($command->amount)->compare($left) > 0) {
                    throw new PaymentRuleViolation('The payment through %1$s has %2$s %3$s left to give back; refund the rest outside the shop.', 'orillacart_refund_over_payment', values: [$this->name($payment->gateway()), $left, $payment->currency()]);
                }
            }
            $refund = new Refund(
                $orderId,
                $payment?->id(),
                $payment?->gateway() ?? $order->paymentGateway,
                match (true) {
                    $command->storeCredit => RefundMethod::StoreCredit,
                    $payment === null => RefundMethod::Manual,
                    default => RefundMethod::Provider,
                },
                Money::of($command->amount),
                $order->currency,
                self::quantities($command->forOrder()),
                $command->shipping,
                $command->restock,
                trim($command->reason),
                $userId,
                $this->clock->now(),
            );
            // Saved before the provider is asked: an answer lost on the way leaves it pending, never forgotten.
            $this->refunds->save($refund);
            if ($recorded !== null) {
                $recorded($refund->id());
            }
            $credit = null;
            if ($payment === null) {
                $credit = $command->storeCredit
                    ? $this->giftCards->refundAsCredit($orderId, $order->customerId, $order->email, $order->billing->firstName, Money::of($command->amount), $order->currency, $userId, $order->locale)
                    : null;
                $this->taxed($refund, $this->orders->refund($orderId, $command->forOrder(), $credit->how ?? 'outside the shop', $userId, credit: $credit?->kind()));
            }

            return [$refund, $payment, $credit];
        });
        if ($payment !== null) {
            $this->send($refund, $payment, $userId);
        } elseif ($credit !== null && !$credit->send()) {
            // A guest's new card goes out once the refund is saved: never a code that a rollback took away. Only its
            // hash is kept, so a card whose email did not go cannot be spent until it gets a new code.
            $this->orders->needsAttention($orderId, \sprintf('The refund was given %s, but the email with its code did not go (the gift card email is off, or the mail failed): give the card a new code on the Gift cards screen to send it.', $credit->how));
        }

        return $this->forOrder($orderId);
    }

    /** The order a refund is of; null for no such refund. */
    public function orderOf(int $refundId): ?int
    {
        return $this->refunds->find($refundId)?->orderId();
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
     * Money that came for an order which closed unpaid and could not open again, its items sold since: all of it
     * goes back through the provider. The order stays closed; its history, and the customer, are told.
     */
    public function giveBackUnclaimed(int $paymentId): void
    {
        $payment = $this->payments->find($paymentId);
        if ($payment === null) {
            return;
        }
        foreach ($this->refunds->ofOrder($payment->orderId()) as $earlier) {
            if ($earlier->paymentId() === $payment->id() && $earlier->status() !== RefundStatus::Failed) {
                // Asked for already (the job ran twice): a second refund would give the money back twice.
                return;
            }
        }
        $refund = new Refund(
            $payment->orderId(),
            $payment->id(),
            $payment->gateway(),
            RefundMethod::Provider,
            $payment->amount(),
            $payment->currency(),
            [],
            false,
            false,
            'The order was closed when the money came, and its items were no longer in stock.',
            null,
            $this->clock->now(),
        );
        try {
            $this->refunds->save($refund);
            $this->send($refund, $payment, null);
        } catch (PaymentRuleViolation $e) {
            // Refused, unanswered (the shop can try it again from the order) or not refundable through the provider.
            $this->logger->error('Payment {payment} came for order {order} after it closed and could not be given back: {message}', ['payment' => $payment->id(), 'order' => $payment->orderId(), 'message' => $e->getMessage()]);
            $this->orders->note($payment->orderId(), \sprintf('%s %s came after the order closed and must be given back: %s', $payment->amount()->toFixed(), $payment->currency(), $e->getMessage()), false);
        }
    }

    /**
     * A refund the provider told of. One the shop sent is recognised by its id, or completes a refund of the
     * same amount still waiting for the provider's answer; any other was made at the provider's own site and is
     * recorded as an amount alone.
     */
    public function notified(Payment $payment, PaymentOutcome $outcome): void
    {
        $refundId = $outcome->refundId ?? '';
        if ($refundId === '') {
            return;
        }
        try {
            // Under the order's lock, as every refund is recorded: a notification sent twice at once waits for its
            // twin, then finds the refund recorded. The refunds' unique (gateway, reference) stands behind it.
            $this->orders->whileLocked($payment->orderId(), function () use ($payment, $outcome, $refundId): void {
                $this->record($payment, $outcome, $refundId);
            });
        } catch (OrderNotFound $e) {
            $this->logger->warning('{gateway} refunded {amount} of order {order}, which the shop could not record: {message}', ['gateway' => $payment->gateway(), 'amount' => $outcome->amount ?? '', 'order' => $payment->orderId(), 'message' => $e->getMessage()]);
        } catch (OrderRuleViolation $e) {
            // Rolled back whole, the refund with it: the shop records what went back by hand.
            $this->logger->warning('{gateway} refunded {amount} of order {order}, which the shop could not record: {message}', ['gateway' => $payment->gateway(), 'amount' => $outcome->amount ?? '', 'order' => $payment->orderId(), 'message' => $e->getMessage()]);
            $this->attention($payment->orderId(), \sprintf('%s refunded %s %s from its own dashboard, which the shop could not record: %s', $this->name($payment->gateway()), $outcome->amount ?? '', $payment->currency(), $e->getMessage()));
        }
    }

    /**
     * @throws OrderNotFound
     */
    private function record(Payment $payment, PaymentOutcome $outcome, string $refundId): void
    {
        $known = $this->refunds->findByProviderReference($payment->gateway(), $refundId);
        if ($known !== null) {
            // One the provider accepted to finish later, now done; any other was recorded before.
            if ($known->status() === RefundStatus::Accepted) {
                $this->completed($known, $refundId, null);
            }

            return;
        }
        // One the shop asked for, whose answer it never heard: known by the id it sent, never by its amount.
        foreach ($this->refunds->ofOrder($payment->orderId()) as $refund) {
            if ($refund->isOpen() && $refund->paymentId() === $payment->id() && $outcome->request !== null && $outcome->request === $refund->idempotencyKey()) {
                $this->completed($refund, $refundId, null);

                return;
            }
        }
        $amount = preg_match('/^\d{1,11}(\.\d{1,4})?$/', $outcome->amount ?? '') === 1 ? Money::of($outcome->amount ?? '0') : Money::zero();

        $order = $this->orders->order($payment->orderId());
        $left = Money::of($order->refundable);
        if ($amount->isZero() || $amount->compare($left) > 0) {
            $this->logger->warning('{gateway} refunded {amount} of order {order}, which has {left} left to refund; record it by hand.', ['gateway' => $payment->gateway(), 'amount' => (string) $amount, 'order' => $payment->orderId(), 'left' => (string) $left]);
            $this->attention($payment->orderId(), \sprintf('%s refunded %s %s from its own dashboard, more than the %s left to refund: check it at the provider, and record what went back as refunded outside the shop.', $this->name($payment->gateway()), (string) $amount, $payment->currency(), (string) $left));

            return;
        }
        $refund = new Refund($payment->orderId(), $payment->id(), $payment->gateway(), RefundMethod::External, $amount, $payment->currency(), [], false, false, '', null, $this->clock->now());
        $refund->refer($refundId);
        $command = new RefundOrder((string) $amount, reason: \sprintf('Made at %s.', $this->name($payment->gateway())));
        // A refusal goes up to notified(), rolling back the whole transaction: rolled back to a savepoint, the
        // refund saved here would stay loaded while its row was gone.
        $this->orders->checkRefund($payment->orderId(), $command);
        $this->refunds->save($refund);
        $this->taxed($refund, $this->orders->refund($payment->orderId(), $command, \sprintf('through %s (refund %s)', $this->name($payment->gateway()), $refundId), null));
        // Made at the provider, the refund names no items: whether they came back is the shop's to say.
        $order = $this->orders->order($payment->orderId());
        if (array_filter($order->lines, static fn(OrderLineView $line): bool => $line->restockable > 0) !== []) {
            $this->orders->needsAttention($payment->orderId(), \sprintf('%s refunded %s %s of the order from its own dashboard: if items came back, put them back in stock from the order.', $this->name($payment->gateway()), (string) $amount, $payment->currency()));
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

            throw new PaymentRuleViolation('%1$s refused the refund: %2$s', 'orillacart_refund_refused', $e, values: [$this->name($payment->gateway()), $e->getMessage()]);
        } catch (ProviderUnavailable $e) {
            $refund->unanswered($e->getMessage());
            $this->refunds->save($refund);
            $this->logger->warning('Refund {refund} of order {order}: {message}', ['refund' => $refund->id(), 'order' => $order->number, 'message' => $e->getMessage()]);

            throw new PaymentRuleViolation('%s did not answer, so the refund waits. Try it again: it is never sent twice.', 'orillacart_refund_unanswered', $e, values: [$this->name($payment->gateway())]);
        } catch (\Throwable $e) {
            // The gateway broke; whether the money moved is not known. The refund waits with the error, to be
            // tried again under the same id (never refunding twice) or given up.
            $refund->unanswered($e->getMessage());
            $this->refunds->save($refund);
            $this->logger->error('Refund {refund} of order {order} failed in {gateway}: {message}', ['refund' => $refund->id(), 'order' => $order->number, 'gateway' => $payment->gateway(), 'message' => $e->getMessage(), 'exception' => $e]);

            throw new PaymentRuleViolation('%1$s failed: %2$s The refund waits: try it again, or give it up.', 'orillacart_refund_unanswered', $e, values: [$this->name($payment->gateway()), $e->getMessage()]);
        }
        if ($result->pending) {
            $this->accepted($refund, $result->reference);

            return;
        }
        $this->completed($refund, $result->reference, $userId);
    }

    /**
     * The provider took the refund and sends the money later: the order learns of it once it is done, so no
     * stock goes back and no email goes out for money that may never move.
     */
    private function accepted(Refund $refund, string $reference): void
    {
        $this->withOrderLocked($refund->orderId(), function () use ($refund, $reference): void {
            $refund = $this->refunds->find($refund->id(), true);
            if ($refund === null || !$refund->accept($reference)) {
                return;
            }
            $this->refunds->save($refund);
            $this->orders->note($refund->orderId(), \sprintf('A refund of %s %s was sent through %s, which sends the money later (refund %s).', $refund->amount()->toFixed(), $refund->currency(), $this->name((string) $refund->gateway()), $reference), false);
        });
    }

    /**
     * Asks the providers how the refunds they accepted to finish later stand: a done one is recorded on its
     * order, one the provider gave up on is failed and its order flagged for the shop.
     *
     * @return int how many were settled
     */
    public function reconcile(int $limit = 50): int
    {
        $settled = 0;
        foreach ($this->refunds->accepted($limit) as $refund) {
            $reference = $refund->providerReference();
            try {
                $gateway = $this->gateways->get((string) $refund->gateway());
            } catch (PaymentMethodNotFound) {
                continue;
            }
            if (!$gateway instanceof ChecksRefunds || $reference === null) {
                continue;
            }
            try {
                $result = $gateway->checkRefund($reference, new Settings($this->methods->findByGateway((string) $refund->gateway())?->settings() ?? []));
            } catch (RefundRefused $e) {
                $this->givenUp($refund, $e->getMessage());
                ++$settled;
                continue;
            } catch (\Throwable $e) {
                $this->logger->warning('Refund {refund} could not be checked at {gateway}: {message}', ['refund' => $refund->id(), 'gateway' => $refund->gateway(), 'message' => $e->getMessage()]);
                continue;
            }
            if (!$result->pending) {
                $this->completed($refund, $result->reference, null);
                ++$settled;
            }
        }

        return $settled;
    }

    /**
     * The provider gave up on a refund it had accepted: the money did not go back.
     */
    private function givenUp(Refund $refund, string $why): void
    {
        $this->withOrderLocked($refund->orderId(), function () use ($refund, $why): void {
            $refund = $this->refunds->find($refund->id(), true);
            if ($refund === null || !$refund->fail($why, $this->clock->now())) {
                return;
            }
            $this->refunds->save($refund);
            $this->events->dispatch(new PaymentInQuestion($refund->orderId(), (string) $refund->gateway(), \sprintf('The refund of %s %s did not go through: %s', $refund->amount()->toFixed(), $refund->currency(), $why)));
        });
    }

    /**
     * The refund row and the order learn of the money together, in one transaction.
     */
    private function completed(Refund $refund, string $reference, ?int $userId): void
    {
        $this->withOrderLocked($refund->orderId(), function () use ($refund, $reference, $userId): void {
            // Read again under the lock: the provider's notification and its answer may both complete it.
            $refund = $this->refunds->find($refund->id(), true);
            if ($refund === null || !$refund->complete($reference, $this->clock->now())) {
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
                if ($this->orders->order($refund->orderId())->paidAt === null) {
                    // Money that came after the order closed: it stays closed, and says so.
                    $this->orders->note($refund->orderId(), \sprintf('The payment came after the order closed, and its items were no longer in stock: the %s %s were given back in full through %s.', $refund->amount()->toFixed(), $refund->currency(), $this->name((string) $refund->gateway())), true);

                    return;
                }
                $this->taxed($refund, $this->orders->refund($refund->orderId(), $command, \sprintf('through %s (refund %s)', $this->name((string) $refund->gateway()), $reference), $userId));
            } catch (OrderNotFound|OrderRuleViolation $e) {
                // The money went back: the order must learn of it, even if not item by item.
                $this->logger->error('Refund {refund} went through but order {order} could not record it: {message}', ['refund' => $refund->id(), 'order' => $refund->orderId(), 'message' => $e->getMessage()]);
                $this->attention($refund->orderId(), \sprintf('%s %s went back to the customer, but the order could not record it: %s Record it on the order as refunded outside the shop.', $refund->amount()->toFixed(), $refund->currency(), $e->getMessage()));
            }
        });
    }

    /**
     * Runs the work in one transaction with the order's row locked, then the refund's: every path that settles a
     * refund (the shop's own, a retry, the provider's notification, the reconciling job) takes the two in that
     * order, so none waits for the other in a circle. An order deleted since locks only the refund.
     *
     * @param \Closure(): void $work
     */
    private function withOrderLocked(int $orderId, \Closure $work): void
    {
        $started = false;
        try {
            $this->orders->whileLocked($orderId, static function () use ($work, &$started): void {
                $started = true;
                $work();
            });
        } catch (OrderNotFound $e) {
            if ($started) {
                throw $e;
            }
            $this->transactions->run($work);
        }
    }

    /** Money moved and the order could not follow: the shop has to. */
    private function attention(int $orderId, string $why): void
    {
        try {
            $this->orders->needsAttention($orderId, $why);
        } catch (OrderNotFound) {
            // The shop's problems say so.
        }
    }

    private function pending(int $refundId): Refund
    {
        $refund = $this->refunds->find($refundId);
        if ($refund === null || $refund->status() !== RefundStatus::Pending) {
            throw new PaymentRuleViolation('Only a refund waiting for the provider can be tried again or given up.', 'orillacart_refund_not_pending');
        }

        return $refund;
    }

    /** What a payment has left to give back: its amount, less the refunds through it that went or are on the way. */
    private function leftOf(Payment $payment): Money
    {
        $left = $payment->amount();
        foreach ($this->refunds->ofOrder($payment->orderId()) as $refund) {
            if ($refund->paymentId() === $payment->id() && $refund->status() !== RefundStatus::Failed) {
                $left = $left->subtract($refund->amount());
            }
        }

        return $left->atLeastZero();
    }

    /** The order recorded the refund: the refund keeps the tax it gave back, for the credit note and the books. */
    private function taxed(Refund $refund, RefundedView $recorded): void
    {
        $refund->taxed(Money::of($recorded->tax), array_map(static fn(TaxLineView $tax): array => ['name' => $tax->name, 'percent' => $tax->percent, 'amount' => $tax->amount, 'code' => $tax->code], $recorded->taxes));
        $this->refunds->save($refund);
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
