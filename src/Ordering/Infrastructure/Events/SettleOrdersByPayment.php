<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Events;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Ordering\Application\OrderService;
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
            $this->orders->markPaid($event->orderId, $event->transactionId, self::note('Paid', $event));

            return;
        } catch (OrderRuleViolation) {
            // Closed before the money came.
        }
        try {
            if ($this->orders->paidAfterClosing($event->orderId, $event->transactionId, self::note('Paid', $event))) {
                return;
            }
        } catch (OrderRuleViolation $e) {
            $this->logger->warning('Payment {payment} ({gateway} {transaction}) completed for order {order}, which cannot take it: refund it. {reason}', [
                'payment' => $event->paymentId, 'gateway' => $event->gateway, 'transaction' => $event->transactionId, 'order' => $event->orderId, 'reason' => $e->getMessage(),
            ]);

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
        } catch (OrderRuleViolation $e) {
            $this->logger->warning('Payment {payment} of order {order} is held by {gateway}, but the order is closed. {reason}', [
                'payment' => $event->paymentId, 'order' => $event->orderId, 'gateway' => $event->gateway, 'reason' => $e->getMessage(),
            ]);
        }
    }

    #[AsEventListener]
    public function failed(PaymentFailed $event): void
    {
        $this->orders->fail($event->orderId, self::note('The payment failed', $event));
    }

    #[AsEventListener]
    public function cancelled(PaymentCancelled $event): void
    {
        $this->orders->cancel($event->orderId, self::note('The customer gave up paying', $event));
    }

    private static function note(string $what, PaymentCompleted|PaymentHeld|PaymentFailed|PaymentCancelled $event): string
    {
        return $event->transactionId === null
            ? \sprintf('%s (%s).', $what, $event->gateway)
            : \sprintf('%s (%s, transaction %s).', $what, $event->gateway, $event->transactionId);
    }
}
