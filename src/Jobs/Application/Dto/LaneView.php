<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * A lane as it is now: what waits in it, and how many workers serve it.
 */
final readonly class LaneView
{
    /**
     * @param list<string> $types the job types that wait in it
     */
    public function __construct(
        public string $queue,
        public array $types,
        public int $due,
        public int $running,
        public int $later,
        public ?string $oldestAt,
        public ?string $nextAt,
        public int $workers,
    ) {}
}
