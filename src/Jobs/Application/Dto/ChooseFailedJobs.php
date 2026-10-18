<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: failed jobs to retry or delete, by id, or all of them.
 */
final readonly class ChooseFailedJobs
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        #[Assert\Count(max: 500)]
        #[Assert\All([new Assert\Regex('/^\d{1,19}$/')])]
        public array $ids = [],
        public bool $all = false,
    ) {}
}
