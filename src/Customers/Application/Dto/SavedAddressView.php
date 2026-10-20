<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;

/**
 * An address in a customer's address book, and whether checkout starts from it for billing or shipping.
 */
final readonly class SavedAddressView
{
    public function __construct(
        public string $id,
        public AddressData $address,
        public bool $billing,
        public bool $shipping,
    ) {}
}
