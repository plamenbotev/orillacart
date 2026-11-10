<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The link emailed to the order's address, which opens its withdrawal. */
final readonly class OpenWithdrawal
{
    public function __construct(
        #[\SensitiveParameter]
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $token,
    ) {}
}
