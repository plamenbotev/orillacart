<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;

/**
 * A customer's account as the accounts store has it.
 */
final readonly class CustomerRecord
{
    public function __construct(
        public int $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $registeredAt,
        public ?AddressData $billing,
        public ?AddressData $shippingAddress,
    ) {}
}
