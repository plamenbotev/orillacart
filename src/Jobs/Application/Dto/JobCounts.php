<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * What the jobs of a type did in an hour or a day.
 */
final readonly class JobCounts
{
    /**
     * @param string $at        the hour or day it starts, ISO 8601
     * @param int    $averageMs average time a job took
     */
    public function __construct(
        public string $at,
        public string $type,
        public int $done,
        public int $retried,
        public int $failed,
        public int $averageMs,
    ) {}
}
