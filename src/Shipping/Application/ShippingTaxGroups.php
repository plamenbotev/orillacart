<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\ShippingRate;
use OrillaCart\Tax\Application\TaxGroupUse;

/** The shipping rates and courier services a tax group taxes. */
final readonly class ShippingTaxGroups implements TaxGroupUse
{
    public function __construct(private CarrierRepository $carriers) {}

    public function uses(int $groupId): ?string
    {
        $rates = 0;
        $services = 0;
        foreach ($this->carriers->all() as $carrier) {
            $rates += \count(array_filter($carrier->rates(), static fn(ShippingRate $rate): bool => $rate->details()->taxGroupId === $groupId));
            $services += $carrier->methodTaxGroupId() === $groupId ? 1 : 0;
        }
        $found = array_filter([
            $rates === 0 ? null : \sprintf($rates === 1 ? '%d shipping rate' : '%d shipping rates', $rates),
            $services === 0 ? null : \sprintf($services === 1 ? '%d courier service' : '%d courier services', $services),
        ]);

        return $found === [] ? null : implode(' and ', $found);
    }
}
