<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Extension\Setting;
use OrillaCart\Shared\Extension\SettingValues;
use OrillaCart\Shipping\Domain\Carrier;

final readonly class CarrierView
{
    /**
     * @param list<ShippingRateView>     $rates          in the order checkout lists them; none when a method prices the carrier
     * @param string|null                $method         the add-on's shipping method that prices it
     * @param array<string, string|bool> $methodSettings secrets come back empty; secrets_set names the saved ones
     * @param list<string>               $secretsSet
     * @param list<PickupLocationView>   $pickupLocations the shop's places to collect orders, for its own pickup
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public int $position,
        public array $rates,
        public ?string $method = null,
        public array $methodSettings = [],
        public array $secretsSet = [],
        public bool $methodTaxable = true,
        public ?int $methodTaxGroupId = null,
        public array $pickupLocations = [],
        public ?SaveDeliverySchedule $deliverySchedule = null,
        public bool $classesKeptToTheirRates = false,
    ) {}

    /**
     * @param list<Setting> $fields the method's settings, to show the saved values without secrets
     */
    public static function fromCarrier(Carrier $carrier, array $fields = []): self
    {
        [$settings, $secretsSet] = SettingValues::shown($fields, $carrier->methodSettings());

        return new self(
            $carrier->id(),
            $carrier->name(),
            $carrier->isActive(),
            $carrier->position(),
            array_map(ShippingRateView::fromRate(...), $carrier->rates()),
            $carrier->method(),
            $settings,
            $secretsSet,
            $carrier->isMethodTaxable(),
            $carrier->methodTaxGroupId(),
            array_map(PickupLocationView::fromLocation(...), $carrier->pickupLocations()),
            $carrier->deliverySchedule() === null ? null : SaveDeliverySchedule::fromSchedule($carrier->deliverySchedule()),
            $carrier->keepsClassesToTheirRates(),
        );
    }
}
