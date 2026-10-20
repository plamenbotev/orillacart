<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * An address as the customer entered it at checkout, or as a check corrects it.
 */
final readonly class CheckedAddress
{
    public const string BILLING = 'billing';
    public const string SHIPPING = 'shipping';

    /**
     * @param string      $kind    BILLING or SHIPPING
     * @param string      $country ISO 3166-1 alpha-2: "BG"
     * @param string|null $state   the state's code where the country has states: "CA"
     */
    public function __construct(
        public string $kind,
        public string $street,
        public string $city,
        public string $postcode,
        public string $country,
        public ?string $state = null,
    ) {}
}
