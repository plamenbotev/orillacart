<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\PickupLocation;

final readonly class PickupLocationView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $address,
        public string $hours,
        public string $fee,
    ) {}

    public static function fromLocation(PickupLocation $location): self
    {
        return new self($location->id, $location->name, $location->address, $location->hours, (string) $location->fee);
    }
}
