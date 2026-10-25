<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Check;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shipping\Application\CarrierService;

/**
 * Shipping to every country the shop sells to: a rate that goes there, a courier's method, or a place to
 * collect from. Without one, customers there cannot order goods that ship.
 */
final readonly class ShippingReadiness implements ReadinessCheck
{
    public function __construct(
        private CarrierService $carriers,
        private SettingsService $settings,
        private GeographyService $geography,
    ) {}

    public function check(): array
    {
        $shop = $this->settings->current();
        $everywhere = false;
        $reached = [];
        foreach ($this->carriers->carriers() as $carrier) {
            if (!$carrier->active) {
                continue;
            }
            if ($carrier->method !== null || $carrier->pickupLocations !== []) {
                $everywhere = true;
                continue;
            }
            foreach ($carrier->rates as $rate) {
                if ($rate->regions === []) {
                    $everywhere = $everywhere || $rate->excludedRegions === [];
                    $reached[] = ['*', $rate->excludedRegions];
                }
                foreach ($rate->regions as $region) {
                    $reached[] = [explode('-', $region)[0], []];
                }
            }
        }
        if ($everywhere) {
            return [];
        }
        if ($reached === []) {
            return [Readiness::stop('shipping.none', Text::of('Nothing ships: customers cannot order goods that need shipping.'), 'shipping')];
        }
        $countries = $shop->sellTo === [] ? array_map(static fn($country): string => $country->code, $this->geography->countries()) : $shop->sellTo;
        $missing = [];
        foreach ($countries as $country) {
            $covered = false;
            foreach ($reached as [$to, $except]) {
                $covered = $covered || $to === $country || ($to === '*' && !\in_array($country, $except, true));
            }
            if (!$covered) {
                $missing[] = $country;
            }
        }
        if ($missing === []) {
            return [];
        }

        return [Readiness::stop('shipping.countries', \count($missing) > 5
            ? Text::of('Nothing ships to %d countries the shop sells to: give them a rate, or sell only where the shop ships.', \count($missing))
            : Text::of('Nothing ships to %s, where the shop sells: give them a rate, or sell only where the shop ships.', implode(', ', $missing)), 'shipping')];
    }
}
