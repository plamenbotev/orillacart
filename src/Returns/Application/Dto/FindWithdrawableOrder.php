<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The order to withdraw from, by its number and the email it was placed with. */
final readonly class FindWithdrawableOrder
{
    public function __construct(
        #[Assert\Positive]
        public int $number,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
    ) {}
}
