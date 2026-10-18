<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;

/**
 * A method whose services can deliver to an office or locker (Rate::$toPickupPoint). Checkout lists the points
 * near the shipping address for the customer to choose; the order keeps the choice, and a shipment goes there.
 */
interface OffersPickupPoints
{
    /**
     * The points that can receive the service's parcels near the address, nearest first, at most a few dozen.
     *
     * @return list<PickupPoint>
     *
     * @throws ProviderUnavailable
     */
    public function pickupPoints(PickupQuery $query, Settings $settings): array;
}
