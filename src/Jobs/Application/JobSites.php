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

    /** Why a network worker leaves a site out: its own plugins, or another theme, than the worker loaded. */
    public const string PLUGINS = 'plugins';
    public const string THEME = 'theme';

    /**
     * @return list<int> the ids of the sites that run the shop and that one worker can serve: on a network, those
     *                   whose plugins and theme are the ones this process loaded
     */
    public function sites(): array;

    /**
     * The sites that run the shop with other plugins or another theme than this process loaded: add-ons hook into
     * the shop's work and a theme's templates and functions shape its emails and documents, so a network worker
     * leaves them to workers of their own (`--url`).
     *
     * @return array<int, string> why each is left out, PLUGINS or THEME, by site id
     */
    public function apart(): array;

    /**
     * Whether this process runs other code than the site would load now: the plugin's files on disk are another
     * build, or the plugins or theme of the site it booted as changed since it started. A long-running worker then
     * stops for its supervisor to start it on what the site runs now.
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
