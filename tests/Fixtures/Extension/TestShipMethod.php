<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Extension;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\Rate;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShippingMethod;

/**
 * A courier's method as an add-on writes it: two services priced by weight, none abroad, and an unreachable
 * courier for postcode 9999. It counts the quotes, so tests see what the shop kept.
 */
final class TestShipMethod implements ShippingMethod
{
    public const string ID = 'test_ship';

    public int $quotes = 0;

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
        ];
    }
}
