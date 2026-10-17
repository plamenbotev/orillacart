<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\Carrier;

final readonly class CarrierView
{
    /**
     * @param list<ShippingRateView> $rates in the order checkout lists them
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public int $position,
        public array $rates,
    ) {}

    public static function fromCarrier(Carrier $carrier): self
    {
        return new self($carrier->id(), $carrier->name(), $carrier->isActive(), $carrier->position(), array_map(ShippingRateView::fromRate(...), $carrier->rates()));
    }
}
