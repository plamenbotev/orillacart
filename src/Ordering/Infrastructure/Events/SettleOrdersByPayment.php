<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Events;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\GiveBackLatePayments;
use OrillaCart\Payment\Domain\Event\PaymentCancelled;
use OrillaCart\Payment\Domain\Event\PaymentCompleted;
use OrillaCart\Payment\Domain\Event\PaymentFailed;
use OrillaCart\Payment\Domain\Event\PaymentHeld;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Moves an order along when its payment ends. Money that arrives for an order that was already cancelled or
 * failed opens it again when its items are still in stock; otherwise the money goes back.
 */
final readonly class SettleOrdersByPayment
{
    public function __construct(
        private OrderService $orders,
        private Jobs $jobs,
        private LoggerInterface $logger,
    ) {}

    #[AsEventListener]
    public function completed(PaymentCompleted $event): void
    {
        try {
            $this->orders->markPaid($event->orderId, $event->transactionId, self::note('Paid', $event), amount: $event->amount);

            return;
        } catch (OrderRuleViolation) {
            // Closed before the money came.
        } catch (OrderNotFound) {
            $this->gone($event, 'completed: refund it at the provider');

            return;
        }
        try {
            if ($this->orders->paidAfterClosing($event->orderId, $event->transactionId, self::note('Paid', $event), $event->amount)) {
                return;
            }
        } catch (OrderRuleViolation $e) {
            $this->logger->warning('Payment {payment} ({gateway} {transaction}) completed for order {order}, which cannot take it: refund it. {reason}', [
                'payment' => $event->paymentId, 'gateway' => $event->gateway, 'transaction' => $event->transactionId, 'order' => $event->orderId, 'reason' => $e->getMessage(),
            ]);
            $this->attention($event->orderId, \sprintf('A payment of %s came through %s (transaction %s), but the order cannot take it: %s Refund it at the provider.', $event->amount, $event->gateway, $event->transactionId, $e->getMessage()));

            return;
        }
        // Given back by a job, once this transaction committed: the provider is not asked while rows are locked.
        $this->jobs->push(GiveBackLatePayments::TYPE, ['payment' => $event->paymentId], (string) $event->paymentId);
    }

    #[AsEventListener]
    public function held(PaymentHeld $event): void
    {
        try {
            $this->orders->hold($event->orderId, self::note('The provider holds the payment for review', $event));
        } catch (OrderNotFound) {
            $this->gone($event, 'is held for review');
        } catch (OrderRuleViolation $e) {
            $this->logger->warning('Payment {payment} of order {order} is held by {gateway}, but the order is closed. {reason}', [
                'payment' => $event->paymentId, 'order' => $event->orderId, 'gateway' => $event->gateway, 'reason' => $e->getMessage(),
            ]);
            $this->attention($event->orderId, \sprintf('%s holds a payment of this order for review, but the order is closed: look at it at the provider, and refund it if it goes through.', $event->gateway));
        }
    }

    #[AsEventListener]
    public function failed(PaymentFailed $event): void
    {
        try {
            $this->orders->fail($event->orderId, self::note('The payment failed', $event));
        } catch (OrderNotFound) {
            $this->gone($event, 'failed');
        }
    }

    #[AsEventListener]
    public function cancelled(PaymentCancelled $event): void
    {
        try {
            $this->orders->cancel($event->orderId, self::note('The customer gave up paying', $event));
        } catch (OrderNotFound) {
            $this->gone($event, 'was given up');
        }
    }

    /** Money moved and the order could not follow: the shop has to. */
    private function attention(int $orderId, string $why): void
    {
        try {
            $this->orders->needsAttention($orderId, $why);
        } catch (OrderNotFound) {
            // Logged already; nothing to flag.
        }
    }

    /** A provider's late word about an order that no longer exists: nothing to move, but the shop should know. */
    private function gone(PaymentCompleted|PaymentHeld|PaymentFailed|PaymentCancelled $event, string $what): void
    {
        $this->logger->error('Payment {payment} ({gateway}) of order {order}, which no longer exists, {what}.', [
            'payment' => $event->paymentId, 'gateway' => $event->gateway, 'order' => $event->orderId, 'what' => $what,
        ]);
    }

    private static function note(string $what, PaymentCompleted|PaymentHeld|PaymentFailed|PaymentCancelled $event): string
    {
        return $event->transactionId === null
            ? \sprintf('%s (%s).', $what, $event->gateway)
            : \sprintf('%s (%s, transaction %s).', $what, $event->gateway, $event->transactionId);
    }
}
