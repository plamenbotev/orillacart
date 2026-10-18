<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

final readonly class OrderAddress
{
    /**
     * @param string      $country ISO 3166-1 alpha-2: "BG"
     * @param string|null $state   the region's code, for countries that have them
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $street,
        public string $city,
        public string $postcode,
        public string $country,
        public ?string $state,
        public string $phone,
    ) {}
}
