<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Events;

use OrillaCart\Ordering\Domain\Event\OrderDeleting;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Payment\Domain\PaymentStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A deleted order takes its payments with it: failed, given up, or offline ones that never came. One still open
 * at its provider keeps the order (the customer may yet pay, and the money needs an order to land on), as does
 * one that was paid.
 */
final readonly class ForgetDeletedOrders
{
    public function __construct(private PaymentRepository $payments) {}

    #[AsEventListener]
    public function deleting(OrderDeleting $event): void
    {
        $payments = $this->payments->ofOrder($event->orderId);
        foreach ($payments as $payment) {
            if ($payment->status() === PaymentStatus::Completed || ($payment->status() === PaymentStatus::Pending && $payment->reference() !== null)) {
                throw new PaymentRuleViolation(\sprintf('Order #%d has a payment still open at %s: it stays until the payment ends.', $event->orderId, $payment->gateway()), 'orillacart_order_payment_open');
            }
        }
        foreach ($payments as $payment) {
            $this->payments->remove($payment);
        }
    }
}
