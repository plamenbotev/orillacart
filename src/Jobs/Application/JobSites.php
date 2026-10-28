<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

/**
 * The shops one worker can serve. On a network every site that runs the shop has a queue of its own in its own
 * tables; a worker for the network goes through them in turn.
 */
interface JobSites
{
    /** Whether the shop runs on a network of sites. */
    public function network(): bool;

    /**
     * @return list<int> the ids of the sites that run the shop
     */
    public function sites(): array;

    /**
     * Runs the work as the site, with the site's own queue.
     *
     * @template T
     *
     * @param \Closure(JobRunner, JobWorkers): T $work
     *
     * @return T
     */
    public function on(int $site, \Closure $work): mixed;
}
