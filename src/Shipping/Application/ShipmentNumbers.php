<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Ordering\Application\OrderLookup;
use OrillaCart\Shipping\Domain\ConsignmentRepository;

/**
 * Finds orders by a shipment's or a parcel's tracking number, for the shop's order search.
 */
final readonly class ShipmentNumbers implements OrderLookup
{
    public function __construct(private ConsignmentRepository $consignments) {}

    public function ordersMatching(string $text): array
    {
        return $this->consignments->orderIdsNumbered($text, self::MAX_FOUND);
    }
}
