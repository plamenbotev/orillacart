<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * The jobs of a type or lane that wait: due now, running, or scheduled ahead.
 */
final readonly class WaitingJobs
{
    /**
     * @param int     $due     due now, not running
     * @param int     $running claimed by a worker
     * @param int     $later   scheduled ahead, as for a sale that starts
     * @param ?string $nextAt  when the soonest scheduled one is due, ISO 8601
     * @param ?string $oldestAt when the longest-waiting due one became due, ISO 8601
     */
    public function __construct(
        public int $due = 0,
        public int $running = 0,
        public int $later = 0,
        public ?string $nextAt = null,
        public ?string $oldestAt = null,
    ) {}
}
