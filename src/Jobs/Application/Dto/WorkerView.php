<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * A long-running worker as it last told.
 */
final readonly class WorkerView
{
    /**
     * @param list<string> $queues   the lanes it serves; none: every lane
     * @param string|null  $working  the job type it is on
     * @param int          $memory   bytes
     */
    public function __construct(
        public string $name,
        public array $queues,
        public string $startedAt,
        public string $seenAt,
        public ?string $working,
        public int $jobsDone,
        public int $memory,
    ) {}
}
