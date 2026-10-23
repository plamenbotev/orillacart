<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * An address as far as tax cares, as the customer gave it; unknown countries and states are ignored.
 */
final readonly class TaxAddress
{
    public function __construct(
        public ?string $country,
        public ?string $state = null,
        public ?string $postcode = null,
        /** For tax services, which rate the street address. */
        public ?string $city = null,
        public ?string $street = null,
    ) {}
}
