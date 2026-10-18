<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

final readonly class FailedJobPage
{
    /**
     * @param list<FailedJobView> $jobs newest first
     */
    public function __construct(
        public array $jobs,
        public int $total,
        public int $page,
        public int $pages,
    ) {}
}
