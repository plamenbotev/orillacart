<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: a visitor makes an account without ordering.
 */
final readonly class RegisterAccount
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 200)]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $password,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $firstName,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $lastName,
    ) {}
}
