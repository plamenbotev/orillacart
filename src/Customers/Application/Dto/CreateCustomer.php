<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the shop makes a customer's account itself (a trade buyer it signed up on paper, a customer phoning
 * in), without the sign-up emails. With no password given, one is made up that nobody knows: the customer sets
 * theirs with WordPress's "lost password" link, or the welcome email's.
 */
final readonly class CreateCustomer
{
    /**
     * @param list<int> $groupIds the customer groups it joins
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 200)]
        public string $email,
        #[Assert\Length(max: 200)]
        public string $firstName = '',
        #[Assert\Length(max: 200)]
        public string $lastName = '',
        /** At least 8 characters; left out, the customer chooses theirs through the lost-password link. */
        #[Assert\Length(min: 8, max: 200)]
        public ?string $password = null,
        #[Assert\All([new Assert\Type('int'), new Assert\Positive()])]
        public array $groupIds = [],
        /** Whether the customer gets the shop's welcome email, with the link to their account. */
        public bool $emailCustomer = false,
    ) {}
}
