<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\FieldAnswer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: correct the customer's email and addresses on an order, and the answers to add-ons' checkout fields
 * it has (by field id). Only orders that ship have a shipping address.
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
        /** @var array<string, string> */
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: FieldAnswer::LONGEST)])]
        public array $fields = [],
    ) {}
}
