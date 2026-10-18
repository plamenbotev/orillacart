<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;

/**
 * A method that books shipments with the courier from the order screen: the shop gets a shipment number, a
 * label to print and a tracking link for the customer, and can cancel a shipment the courier has not picked up.
 */
interface CreatesShipments
{
    /**
     * Books the shipment.
     *
     * @throws ProviderUnavailable also when the courier refuses it, with its reason
     */
    public function createShipment(ShipmentRequest $request, Settings $settings): BookedShipment;

    /**
     * The shipment's label, to print and stick on the parcel.
     *
     * @throws ProviderUnavailable
     */
    public function label(string $shipmentNumber, Settings $settings): Label;

    /**
     * @throws ProviderUnavailable when the courier cannot cancel it, as when it has picked it up
     */
    public function cancelShipment(string $shipmentNumber, Settings $settings): void;
}
