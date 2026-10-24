<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application\Dto;

final readonly class ProblemPage
{
    /**
     * @param list<ProblemView> $problems the latest seen first
     */
    public function __construct(
        public array $problems,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
