<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Checkout\Application\EmailConfirmations;
use Symfony\Component\Validator\Constraints as Assert;

/** The link that confirms a guest's email for ordering, followed: its token. */
final readonly class ConfirmEmail
{
    public function __construct(
        #[\SensitiveParameter]
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^' . EmailConfirmations::PATTERN . '$/')]
        public string $token,
    ) {}
}
