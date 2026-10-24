<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\Events;

use OrillaCart\Ordering\Domain\Event\OrderDeleting;
use OrillaCart\Ordering\Domain\Event\OrderReaddressing;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ConsignmentStatus;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order with a shipment booked at a carrier stays, and keeps the address the shipment goes to: the parcel
 * exists. Cancelled shipments go with the order (the database deletes them along), and let it ship elsewhere.
 */
final readonly class KeepShippedOrders
{
    public function __construct(private ConsignmentRepository $consignments) {}

    #[AsEventListener]
    public function deleting(OrderDeleting $event): void
    {
        foreach ($this->consignments->forOrder($event->orderId) as $consignment) {
            if ($consignment->status() !== ConsignmentStatus::Cancelled) {
                throw new ShippingRuleViolation('Order #%1$d has a shipment with %2$s: cancel the shipment first.', 'orillacart_order_shipped', values: [$event->orderId, $consignment->carrier()]);
            }
        }
    }

    #[AsEventListener]
    public function readdressing(OrderReaddressing $event): void
    {
        foreach ($this->consignments->forOrder($event->orderId) as $consignment) {
            if ($consignment->status()->isOpen()) {
                throw new ShippingRuleViolation('Order #%1$d has a shipment booked with %2$s to its address: cancel the shipment to change where it goes.', 'orillacart_order_shipped', values: [$event->orderId, $consignment->carrier()]);
            }
        }
    }
}
