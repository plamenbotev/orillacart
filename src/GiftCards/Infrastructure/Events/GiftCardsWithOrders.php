<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Infrastructure\Events;

use OrillaCart\GiftCards\Application\GiftCardPayments;
use OrillaCart\GiftCards\Application\GiftCardSales;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\OrderStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order that is cancelled or fails gives its gift cards and store credit back what they paid; one opened
 * again takes it again, and what the cards no longer have is left to pay the customer's way. A paid order makes the gift cards it bought.
 */
final readonly class GiftCardsWithOrders
{
    public function __construct(
        private GiftCardPayments $payments,
        private GiftCardSales $sales,
        private OrderService $orders,
    ) {}

    #[AsEventListener]
    public function statusChanged(OrderStatusChanged $event): void
    {
        $closed = [OrderStatus::Cancelled, OrderStatus::Failed];
        if (\in_array($event->status, $closed, true)) {
            $this->payments->giveBack($event->orderId);
        } elseif (\in_array($event->previous, $closed, true)) {
            $short = $this->payments->reclaim($event->orderId);
            if (!$short->isZero()) {
                // What the cards no longer pay is asked of the customer's way to pay, as Pay now does.
                $this->orders->giftCardsFellShort($event->orderId, $short);
            }
        }
    }

    #[AsEventListener]
    public function paid(OrderPaid $event): void
    {
        $this->sales->issueFor($event->orderId);
    }

    #[AsEventListener]
    public function refunded(OrderRefunded $event): void
    {
        $this->sales->refunded($event->orderId, $event->quantities, $event->full);
    }
}
