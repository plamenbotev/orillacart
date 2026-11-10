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
     * @return list<int> the ids of the sites that run the shop and that one worker can serve: on a network, those
     *                   whose plugins are the ones this process loaded
     */
    public function sites(): array;

    /**
     * The sites that run the shop with other plugins than the ones this process loaded: add-ons hook into the
     * shop's work, so a network worker leaves them to workers of their own (`--url`).
     *
     * @return list<int>
     */
    public function apart(): array;

    /**
     * Whether the plugin's files on disk are another build than the one this process runs: the plugin was updated,
     * so a long-running worker stops for its supervisor to start it on the new code.
     */
    public function outdated(): bool;

    /** The network's own work a worker for it does between rounds, such as bringing its shops up to date. */
    public function chores(): void;

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
