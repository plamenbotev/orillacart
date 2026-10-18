<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\Events;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Ordering\Domain\Event\OrderChanged;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Reports\Application\ProjectOrders;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Queues an order to be counted again whenever it changes. One job per order waits at a time: an order that
 * changes five times in a minute is counted once.
 */
final readonly class CountChangedOrders
{
    public function __construct(private Jobs $jobs) {}

    #[AsEventListener]
    public function placed(OrderPlaced $event): void
    {
        $this->count($event->orderId);
    }

    #[AsEventListener]
    public function statusChanged(OrderStatusChanged $event): void
    {
        $this->count($event->orderId);
    }

    #[AsEventListener]
    public function refunded(OrderRefunded $event): void
    {
        $this->count($event->orderId);
    }

    #[AsEventListener]
    public function changed(OrderChanged $event): void
    {
        $this->count($event->orderId);
    }

    #[AsEventListener]
    public function deleted(OrderDeleted $event): void
    {
        $this->count($event->orderId);
    }

    private function count(int $orderId): void
    {
        $this->jobs->push(ProjectOrders::TYPE, [], (string) $orderId);
    }
}
