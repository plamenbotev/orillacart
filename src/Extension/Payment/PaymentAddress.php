<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * Where the order ships, for a provider that protects sellers who send to the address it was told.
 */
final readonly class PaymentAddress
{
    public function __construct(
        public string $name,
        public string $street,
        public string $city,
        public string $postcode,
        public string $country,
        public ?string $state,
    ) {}
}
