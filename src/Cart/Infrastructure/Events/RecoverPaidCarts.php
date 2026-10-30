<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\Events;

use OrillaCart\Cart\Application\AbandonedCarts;
use OrillaCart\Cart\Domain\Event\LeftCartOrdered;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\OrderRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A cart left at checkout is recovered when the order placed from it is paid, not when it is placed: an order
 * never paid won nothing back. An order paid as it was placed (by gift cards, or costing nothing) is paid before
 * its cart is linked to it, so the link looks too.
 */
final readonly class RecoverPaidCarts
{
    public function __construct(
        private AbandonedCarts $abandoned,
        private OrderRepository $orders,
    ) {}

    #[AsEventListener]
    public function paid(OrderPaid $event): void
    {
        $this->abandoned->orderPaid($event->orderId);
    }

    #[AsEventListener]
    public function ordered(LeftCartOrdered $event): void
    {
        if ($this->orders->find($event->orderId)?->paidAt() !== null) {
            $this->abandoned->orderPaid($event->orderId);
        }
    }
}
