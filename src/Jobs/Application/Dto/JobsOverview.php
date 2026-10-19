<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * The background jobs as the shop sees them: what waits in each lane, the workers, whether anything runs the
 * queue, what is scheduled ahead and what the jobs did lately.
 */
final readonly class JobsOverview
{
    /**
     * @param list<LaneView>         $lanes
     * @param list<TypeView>         $types
     * @param list<WorkerView>       $workers
     * @param 'workers'|'cron'|'none' $runner what runs the queue: workers, WP-Cron, or nothing lately
     * @param list<ScheduledJobView> $scheduled the soonest scheduled ahead
     * @param list<JobCounts>        $hours  the last 24 hours, per type
     * @param list<JobCounts>        $days   the last 30 days, per type
     * @param bool                   $cronDisabled WP-Cron does not run on visits: a server's cron or workers have to
     *                                              run the jobs
     */
    public function __construct(
        public array $lanes,
        public array $types,
        public array $workers,
        public string $runner,
        public ?string $cronRanAt,
        public int $failed,
        public array $scheduled,
        public array $hours,
        public array $days,
        public string $now,
        public bool $cronDisabled = false,
    ) {}
}
