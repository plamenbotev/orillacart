<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\PickupLocation;
use OrillaCart\Shipping\Domain\ShippingRate;

/**
 * Carriers' names, their rates' and pickup places' in the language shown, read together for checkout; each falls
 * back to its own where it has no translation.
 */
final readonly class ShippingWords
{
    /**
     * @param array<int, array<string, string>> $carriers by carrier id and field
     * @param array<int, string>                $rates    by rate id
     */
    public function __construct(
        private array $carriers = [],
        private array $rates = [],
    ) {}

    public function carrier(Carrier $carrier): string
    {
        return $this->carriers[$carrier->id()]['name'] ?? $carrier->name();
    }

    public function rate(ShippingRate $rate): string
    {
        return $this->rates[$rate->id()] ?? $rate->name();
    }

    public function place(Carrier $carrier, PickupLocation $location): PickupLocation
    {
        $fields = $this->carriers[$carrier->id()] ?? [];
        $text = static fn(string $field, string $own): string => $fields[CarrierTranslations::pickupField($location->id, $field)] ?? $own;

        return new PickupLocation($location->id, $text('name', $location->name), $text('address', $location->address), $text('hours', $location->hours), $location->fee);
    }
}
