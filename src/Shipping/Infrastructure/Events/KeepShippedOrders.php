<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\Events;

use OrillaCart\Ordering\Domain\Event\OrderDeleting;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ConsignmentStatus;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order with a shipment booked at a carrier stays: the parcel exists. Cancelled shipments go with the order
 * (the database deletes them along).
 */
final readonly class KeepShippedOrders
{
    public function __construct(private ConsignmentRepository $consignments) {}

    #[AsEventListener]
    public function deleting(OrderDeleting $event): void
    {
        foreach ($this->consignments->forOrder($event->orderId) as $consignment) {
            if ($consignment->status() !== ConsignmentStatus::Cancelled) {
                throw new ShippingRuleViolation(\sprintf('Order #%d has a shipment with %s: cancel the shipment first.', $event->orderId, $consignment->carrier()), 'orillacart_order_shipped');
            }
        }
    }
}
