<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Tax;

/**
 * An address as tax services rate it. Parts not known yet, such as the street before the customer typed it, are
 * empty.
 */
final readonly class TaxAddress
{
    /**
     * @param string      $country ISO 3166-1 alpha-2: "US"
     * @param string|null $region  the state's code: "CA"
     */
    public function __construct(
        public string $country,
        public ?string $region,
        public string $postcode = '',
        public string $city = '',
        public string $street = '',
    ) {}
}
