<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\BookedShipment;
use OrillaCart\Extension\Shipping\CreatesShipments;
use OrillaCart\Extension\Shipping\Label;
use OrillaCart\Extension\Shipping\ShipmentRequest;
use OrillaCart\Extension\Shipping\Tracking;
use OrillaCart\Extension\Shipping\TracksShipments;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/**
 * A scripted shipping method with `create_shipment(request, settings)`: it books shipments, and with `label`,
 * `cancel_shipment` and `track` prints labels, cancels and tracks them.
 */
final class ScriptedCourier extends ScriptedShippingMethod implements CreatesShipments, TracksShipments
{
    public function createShipment(ShipmentRequest $request, Settings $settings): BookedShipment
    {
        try {
            $booked = $this->expect($this->call('create_shipment', [Values::shipmentRequest($request), self::values($settings)]), BookedShipment::class, 'create_shipment');
        } catch (HandlerFailed $e) {
            throw new ProviderUnavailable($this->title(), $e->getMessage(), $e);
        }
        \assert($booked instanceof BookedShipment);

        return $booked;
    }

    public function label(string $shipmentNumber, Settings $settings): Label
    {
        if (!$this->has('label')) {
            throw new ProviderUnavailable($this->title(), 'This courier prints no labels.');
        }
        try {
            $label = $this->expect($this->call('label', [$shipmentNumber, self::values($settings)]), Label::class, 'label');
        } catch (HandlerFailed $e) {
            throw new ProviderUnavailable($this->title(), $e->getMessage(), $e);
        }
        \assert($label instanceof Label);

        return $label;
    }

    public function cancelShipment(string $shipmentNumber, Settings $settings): void
    {
        if (!$this->has('cancel_shipment')) {
            throw new ProviderUnavailable($this->title(), 'This courier takes no cancellations.');
        }
        try {
            $this->call('cancel_shipment', [$shipmentNumber, self::values($settings)]);
        } catch (HandlerFailed $e) {
            throw new ProviderUnavailable($this->title(), $e->getMessage(), $e);
        }
    }

    public function track(string $shipmentNumber, Settings $settings): Tracking
    {
        if (!$this->has('track')) {
            return new Tracking('booked');
        }
        try {
            $tracking = $this->expect($this->call('track', [$shipmentNumber, self::values($settings)]), Tracking::class, 'track');
        } catch (HandlerFailed $e) {
            throw new ProviderUnavailable($this->title(), $e->getMessage(), $e);
        }
        \assert($tracking instanceof Tracking);

        return $tracking;
    }
}
