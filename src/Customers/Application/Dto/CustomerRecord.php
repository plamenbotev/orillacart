<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Customers\Application\AddressBook;

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
        public AddressBook $addresses = new AddressBook(),
    ) {}
}
