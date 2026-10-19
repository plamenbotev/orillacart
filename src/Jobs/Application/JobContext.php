<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

/**
 * What jobs run in, wherever they run (after a request, from WP-Cron, in a worker): nobody logged in, in the site's
 * language, so neither the user whose request ran them nor their profile language reaches what a job writes.
 */
interface JobContext
{
    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function run(\Closure $work): mixed;
}
