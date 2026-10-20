<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: what the address checks say of the addresses on the checkout form.
 */
final readonly class CheckAddresses
{
    public function __construct(
        #[Assert\Valid]
        public AddressData $billing,
        #[Assert\Valid]
        public ?AddressData $shippingAddress = null,
    ) {}
}
