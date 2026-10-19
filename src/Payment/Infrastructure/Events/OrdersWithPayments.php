<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Events;

use OrillaCart\Ordering\Domain\Event\OrderDeleting;
use OrillaCart\Ordering\Domain\Event\OrderMarkingPaid;
use OrillaCart\Ordering\Domain\Event\OrderRepricing;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Payment\Domain\PaymentStatus;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Orders as their payments hold them. A deleted order takes its payments with it: failed, given up, or offline
 * ones that never came. One still open at its provider keeps the order (the customer may yet pay, and the money
 * needs an order to land on), as does one that was paid; and the provider was asked for the order's total, which
 * so cannot change. A payment the provider held, then the shop marks the order paid: the money came, and the
 * payment completes with it.
 */
final readonly class OrdersWithPayments
{
    public function __construct(private PaymentRepository $payments, private ClockInterface $clock) {}

    #[AsEventListener]
    public function deleting(OrderDeleting $event): void
    {
        $payments = $this->payments->ofOrder($event->orderId);
        foreach ($payments as $payment) {
            if ($payment->status() === PaymentStatus::Completed || self::atProvider($payment)) {
                throw new PaymentRuleViolation(\sprintf('Order #%d has a payment still open at %s: it stays until the payment ends.', $event->orderId, $payment->gateway()), 'orillacart_order_payment_open');
            }
        }
        foreach ($payments as $payment) {
            $this->payments->remove($payment);
        }
    }

    #[AsEventListener]
    public function repricing(OrderRepricing $event): void
    {
        foreach ($this->payments->ofOrder($event->orderId) as $payment) {
            if (self::atProvider($payment)) {
                throw new PaymentRuleViolation(\sprintf('Order #%d has a payment open at %s for its total: its items change once that payment ends.', $event->orderId, $payment->gateway()), 'orillacart_order_payment_open');
            }
        }
    }

    #[AsEventListener]
    public function markingPaid(OrderMarkingPaid $event): void
    {
        $held = array_filter($this->payments->ofOrder($event->orderId), static fn(Payment $payment): bool => $payment->status() === PaymentStatus::Pending && $payment->transactionId() !== null);
        $payment = end($held);
        if ($payment === false) {
            return;
        }
        $payment->complete(null, $this->clock->now());
        $this->payments->save($payment);
        $event->transactionId = $payment->transactionId();
    }

    private static function atProvider(Payment $payment): bool
    {
        return $payment->status() === PaymentStatus::Pending && $payment->reference() !== null;
    }
}
