<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shipping\Application\Dto\ConsignmentView;
use OrillaCart\Shipping\Domain\Consignment;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ConsignmentStatus;

/**
 * The shipments a customer can follow: an order's, except the cancelled ones. The receipt and the emails show them.
 */
final readonly class ShipmentTracking
{
    public function __construct(private ConsignmentRepository $consignments) {}

    /**
     * @return list<ConsignmentView> oldest first
     */
    public function forOrder(int $orderId): array
    {
        return array_values(array_map(
            ConsignmentView::fromConsignment(...),
            array_filter($this->consignments->forOrder($orderId), static fn(Consignment $consignment): bool => $consignment->status() !== ConsignmentStatus::Cancelled),
        ));
    }
}
