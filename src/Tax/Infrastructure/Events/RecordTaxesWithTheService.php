<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\Events;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Tax\Application\RecordTaxes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Queues what the tax service that taxed an order is to be told: the sale when it is paid, each refund, and a
 * cancellation. Orders the shop's own rates taxed tell nobody.
 */
final readonly class RecordTaxesWithTheService
{
    public function __construct(
        private Jobs $jobs,
        private OrderService $orders,
    ) {}

    #[AsEventListener]
    public function paid(OrderPaid $event): void
    {
        if ($this->taxedByAService($event->orderId)) {
            $this->jobs->push(RecordTaxes::TYPE, ['order' => $event->orderId, 'action' => RecordTaxes::COMMIT], 'commit:' . $event->orderId);
        }
    }

    #[AsEventListener]
    public function refunded(OrderRefunded $event): void
    {
        if ($this->taxedByAService($event->orderId)) {
            // The refund has no id of its own: a reference made here names it, in the job's key and in what the
            // handler keeps once the service was told.
            $reference = \sprintf('%d:%s', $event->orderId, bin2hex(random_bytes(8)));
            $this->jobs->push(RecordTaxes::TYPE, ['order' => $event->orderId, 'action' => RecordTaxes::REFUND, 'amount' => $event->amount, 'tax' => $event->tax, 'full' => $event->full, 'reference' => $reference], 'refund:' . $reference);
        }
    }

    #[AsEventListener]
    public function cancelled(OrderStatusChanged $event): void
    {
        if ($event->status === OrderStatus::Cancelled && $this->taxedByAService($event->orderId)) {
            $this->jobs->push(RecordTaxes::TYPE, ['order' => $event->orderId, 'action' => RecordTaxes::VOID], 'void:' . $event->orderId);
        }
    }

    private function taxedByAService(int $orderId): bool
    {
        try {
            return $this->orders->order($orderId)->taxProvider !== null;
        } catch (OrderNotFound) {
            return false;
        }
    }
}
