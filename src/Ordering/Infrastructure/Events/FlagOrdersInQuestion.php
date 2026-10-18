<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Events;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Domain\Event\PaymentInQuestion;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order whose money the provider puts in question (reversed, disputed, a refund that did not go through) is
 * flagged for the shop, which is told.
 */
final readonly class FlagOrdersInQuestion
{
    public function __construct(
        private OrderService $orders,
        private LoggerInterface $logger,
    ) {}

    #[AsEventListener]
    public function inQuestion(PaymentInQuestion $event): void
    {
        try {
            $this->orders->needsAttention($event->orderId, $event->reason);
        } catch (OrderNotFound) {
            $this->logger->warning('{gateway} says the money of order {order}, which was deleted, is in question: {reason}', ['gateway' => $event->gateway, 'order' => $event->orderId, 'reason' => $event->reason]);
        }
    }
}
