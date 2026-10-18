<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;

/**
 * A method that says where a shipment is. The shop asks every hour for the shipments still on their way; a
 * delivered one completes its shipped order.
 */
interface TracksShipments
{
    /**
     * @throws ProviderUnavailable
     */
    public function track(string $shipmentNumber, Settings $settings): Tracking;
}
