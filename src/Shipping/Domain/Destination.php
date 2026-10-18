<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * Where a parcel goes: an ISO 3166-1 country code, for countries with states the state's code, and the postcode
 * when the customer has given it.
 */
final readonly class Destination
{
    public function __construct(
        public string $country,
        public ?string $state = null,
        public ?string $postcode = null,
    ) {}
}
