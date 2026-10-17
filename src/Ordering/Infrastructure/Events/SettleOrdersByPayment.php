<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Events;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Domain\Event\PaymentCancelled;
use OrillaCart\Payment\Domain\Event\PaymentCompleted;
use OrillaCart\Payment\Domain\Event\PaymentFailed;
use OrillaCart\Payment\Domain\Event\PaymentHeld;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Moves an order along when its payment ends. Money that arrives for an order that was already cancelled
 * is logged for the shop to refund; the order stays cancelled.
 */
final readonly class SettleOrdersByPayment
{
    public function __construct(
        private OrderService $orders,
        private LoggerInterface $logger,
    ) {}

    #[AsEventListener]
    public function completed(PaymentCompleted $event): void
    {
        try {
            $this->orders->markPaid($event->orderId, $event->transactionId);
        } catch (OrderRuleViolation $e) {
            $this->logger->warning('Payment {payment} ({gateway} {transaction}) completed for order {order}, which is closed: refund it. {reason}', [
                'payment' => $event->paymentId, 'gateway' => $event->gateway, 'transaction' => $event->transactionId, 'order' => $event->orderId, 'reason' => $e->getMessage(),
            ]);
        }
    }

    #[AsEventListener]
    public function held(PaymentHeld $event): void
    {
        try {
            $this->orders->hold($event->orderId);
        } catch (OrderRuleViolation $e) {
            $this->logger->warning('Payment {payment} of order {order} is held by {gateway}, but the order is closed. {reason}', [
                'payment' => $event->paymentId, 'order' => $event->orderId, 'gateway' => $event->gateway, 'reason' => $e->getMessage(),
            ]);
        }
    }

    #[AsEventListener]
    public function failed(PaymentFailed $event): void
    {
        $this->orders->fail($event->orderId);
    }

    #[AsEventListener]
    public function cancelled(PaymentCancelled $event): void
    {
        $this->orders->cancel($event->orderId);
    }
}
