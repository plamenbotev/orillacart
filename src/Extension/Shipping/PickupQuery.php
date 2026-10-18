<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * Where the customer wants pickup points: around the shipping address, for one service.
 */
final readonly class PickupQuery
{
    public function __construct(
        public string $service,
        public string $country,
        public ?string $postcode,
        public ?string $city,
    ) {}
}
