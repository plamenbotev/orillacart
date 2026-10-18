<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * The rest of the order, as a field's check may need it.
 */
final readonly class FieldContext
{
    /**
     * @param string      $billingCountry  ISO 3166-1 alpha-2: "BG"
     * @param string|null $shippingCountry null when the order does not ship
     * @param int|null    $customerId      the WordPress user, null for a guest
     */
    public function __construct(
        public string $email,
        public string $billingCountry,
        public ?string $shippingCountry,
        public ?int $customerId,
    ) {}
}
