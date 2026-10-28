<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Jobs\Application\JobSites;
use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Upgrades\SiteUpgrades;

/**
 * The network's shops for a worker: each site in a container of its own (SiteKernels), so its jobs run on its
 * tables, in its language and with its settings.
 */
final readonly class NetworkJobSites implements JobSites
{
    public function __construct(
        private SiteUpgrades $upgrades,
        private PluginContext $context,
    ) {}

    public function network(): bool
    {
        return is_multisite();
    }

    public function sites(): array
    {
        return array_keys($this->upgrades->shops());
    }

    public function on(int $site, \Closure $work): mixed
    {
        return SiteKernels::run($site, $this->context, static function (Kernel $kernel) use ($work): mixed {
            $runner = $kernel->container()->get(JobRunner::class);
            $workers = $kernel->container()->get(JobWorkers::class);
            \assert($runner instanceof JobRunner && $workers instanceof JobWorkers);

            return $work($runner, $workers);
        });
    }
}
