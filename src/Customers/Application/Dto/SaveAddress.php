<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: an address for a customer's address book, and whether checkout starts from it for billing or for
 * shipping from now on.
 */
final readonly class SaveAddress
{
    public function __construct(
        #[Assert\Valid]
        public AddressData $address,
        public bool $billing = false,
        public bool $shipping = false,
    ) {}
}
