<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shipping\Application\Dto\ConsignmentView;
use OrillaCart\Shipping\Domain\Consignment;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ConsignmentStatus;

/**
 * The shipments a customer can follow: an order's, except the cancelled ones. The receipt and the emails show them.
 */
final readonly class ShipmentTracking
{
    public function __construct(
        private ConsignmentRepository $consignments,
        private OrderService $orders,
    ) {}

    /**
     * @return list<ConsignmentView> oldest first
     */
    public function forOrder(int $orderId, bool $forCustomer = true): array
    {
        try {
            $order = $forCustomer ? $this->orders->inCustomerWords($orderId) : $this->orders->order($orderId);
            $names = array_map(static fn(OrderLineView $line): string => $line->name, $order->lines);
        } catch (OrderNotFound) {
            return [];
        }

        return array_values(array_map(
            static fn(Consignment $consignment): ConsignmentView => ConsignmentView::fromConsignment($consignment, $names),
            array_filter($this->consignments->forOrder($orderId), static fn(Consignment $consignment): bool => $consignment->status() !== ConsignmentStatus::Cancelled),
        ));
    }
}
