<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class FailedJobQuery
{
    public function __construct(
        #[Assert\Length(max: 80)]
        public ?string $type = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 20,
    ) {}
}
