<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the addresses checkout fills in for the customer; null forgets one.
 */
final readonly class SaveAddresses
{
    public function __construct(
        #[Assert\Valid]
        public ?AddressData $billing = null,
        #[Assert\Valid]
        public ?AddressData $shippingAddress = null,
    ) {}
}
