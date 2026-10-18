<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Extension;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\BookedShipment;
use OrillaCart\Extension\Shipping\CreatesShipments;
use OrillaCart\Extension\Shipping\Label;
use OrillaCart\Extension\Shipping\OffersPickupPoints;
use OrillaCart\Extension\Shipping\PickupPoint;
use OrillaCart\Extension\Shipping\PickupQuery;
use OrillaCart\Extension\Shipping\Rate;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentRequest;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Extension\Shipping\Tracking;
use OrillaCart\Extension\Shipping\TracksShipments;

/**
 * A courier's method as an add-on writes it, with every capability: services priced by weight (economy,
 * express, and office for its two Sofia offices), none abroad, and an unreachable courier for postcode 9999.
 * It books shipments TS1, TS2…, refuses to cancel one it picked up, and tracks as a test says. It counts and
 * keeps what it was asked, so tests see what the shop sent and kept.
 */
final class TestShipMethod implements ShippingMethod, OffersPickupPoints, CreatesShipments, TracksShipments
{
    public const string ID = 'test_ship';

    public int $quotes = 0;
    /** @var list<ShipmentRequest> */
    public array $booked = [];
    /** @var list<string> shipment numbers the courier has picked up */
    public array $pickedUp = [];
    /** @var array<string, Tracking> by shipment number */
    public array $tracking = [];

    public function id(): string
    {
        return self::ID;
    }

    public function name(): string
    {
        return 'Test Ship';
    }

    public function settings(): array
    {
        return [
            new Setting('account', 'Account', required: true),
            new Setting('per_kg', 'Price per kilogram', Setting::NUMBER),
        ];
    }

    public function isReady(Settings $settings): bool
    {
        return $settings->text('account') !== '';
    }

    public function quote(Shipment $shipment, Settings $settings): array
    {
        ++$this->quotes;
        if ($shipment->postcode === '9999') {
            throw new ProviderUnavailable('Test Ship', 'The courier is down.');
        }
        if ($shipment->country !== 'BG') {
            return [];
        }
        $perKg = $settings->text('per_kg') === '' ? 1.0 : (float) $settings->text('per_kg');
        $economy = 3 + $perKg * (float) $shipment->weight();

        return [
            new Rate('economy', 'Economy', number_format($economy, 2, '.', ''), 3),
            new Rate('express', 'Express', number_format($economy * 2, 2, '.', ''), 1),
            new Rate('office', 'To an office', number_format($economy - 1, 2, '.', ''), 2, toPickupPoint: true),
        ];
    }

    public function pickupPoints(PickupQuery $query, Settings $settings): array
    {
        return $query->country !== 'BG' ? [] : [
            new PickupPoint('SOF-1', 'Office Vitosha', '12 Vitosha Blvd, 1000 Sofia', 'Mon–Fri 9–18'),
            new PickupPoint('SOF-2', 'Locker Mall', '101 Bulgaria Blvd, 1404 Sofia'),
        ];
    }

    public function createShipment(ShipmentRequest $request, Settings $settings): BookedShipment
    {
        if ($request->street === 'Nowhere') {
            throw new ProviderUnavailable('Test Ship', 'The address is not served.');
        }
        $this->booked[] = $request;
        $number = 'TS' . \count($this->booked);

        return new BookedShipment($number, 'https://track.test/' . $number);
    }

    public function label(string $shipmentNumber, Settings $settings): Label
    {
        return new Label('%PDF-1.4 label of ' . $shipmentNumber);
    }

    public function cancelShipment(string $shipmentNumber, Settings $settings): void
    {
        if (\in_array($shipmentNumber, $this->pickedUp, true)) {
            throw new ProviderUnavailable('Test Ship', 'The courier has picked it up.');
        }
    }

    public function track(string $shipmentNumber, Settings $settings): Tracking
    {
        return $this->tracking[$shipmentNumber] ?? new Tracking(Tracking::ON_THE_WAY, 'At the depot');
    }
}
