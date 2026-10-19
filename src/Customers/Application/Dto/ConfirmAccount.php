<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the visitor followed the link signing up emailed them, and chose their password.
 */
final readonly class ConfirmAccount
{
    public function __construct(
        #[Assert\Regex(pattern: '/^[a-f0-9]{64}$/')]
        public string $token,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $password,
    ) {}
}
