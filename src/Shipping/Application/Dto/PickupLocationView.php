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
        /** @var array<string, array<string, string>> its name, address and hours in the site's other languages, by field and locale */
        public array $translations = [],
    ) {}

    /**
     * @param array<string, array<string, string>> $translations
     */
    public static function fromLocation(PickupLocation $location, array $translations = []): self
    {
        return new self($location->id, $location->name, $location->address, $location->hours, (string) $location->fee, $translations);
    }
}
