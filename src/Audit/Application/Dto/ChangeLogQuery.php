<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeLogQuery
{
    public function __construct(
        #[Assert\Length(max: 64)]
        public ?string $area = null,
        #[Assert\Positive]
        public ?int $userId = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 50,
    ) {}
}
