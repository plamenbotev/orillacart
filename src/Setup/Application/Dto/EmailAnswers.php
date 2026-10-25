<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Who the shop's emails come from, and where the shop's own (new orders) go.
 */
final readonly class EmailAnswers
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $fromName,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $fromAddress,
        #[Assert\Email]
        public ?string $shopAddress = null,
    ) {}
}
