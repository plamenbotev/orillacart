<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: correct the customer's email and addresses on an order. Only orders that ship have a shipping address.
 */
final readonly class ChangeOrderContact
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 200)]
        public string $email,
        #[Assert\Valid]
        public AddressData $billing,
        #[Assert\Valid]
        public ?AddressData $shippingAddress = null,
    ) {}
}
