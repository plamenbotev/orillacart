<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application\Dto;

/**
 * Where the shop sells from, and the money and units that go with it: what the setup sets in one go.
 */
final readonly class ShopLocation
{
    public function __construct(
        public string $country,
        public ?string $state,
        public ?string $postcode,
        public string $currency,
        public string $currencySign,
        public string $currencyPosition,
        public string $weightUnit,
        public string $dimensionUnit,
    ) {}
}
