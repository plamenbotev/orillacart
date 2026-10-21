<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateReviewSettings
{
    public function __construct(
        #[Assert\Choice(choices: ['off', 'anyone', 'buyers'])]
        public string $policy = 'anyone',
    ) {}
}
