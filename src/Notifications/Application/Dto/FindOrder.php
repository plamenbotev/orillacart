<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: email the link to the receipt of the order of this number, when it was placed with this email.
 */
final readonly class FindOrder
{
    public function __construct(
        #[Assert\Positive(message: 'Enter the order number from your confirmation email.')]
        public int $number,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
    ) {}
}
