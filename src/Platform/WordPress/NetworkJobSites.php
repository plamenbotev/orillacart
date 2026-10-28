<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Jobs\Application\JobSites;
use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Upgrades\NetworkUpgrades;
use OrillaCart\Platform\WordPress\Upgrades\SiteUpgrades;

/**
 * The network's shops for a worker: each site in a container of its own (SiteKernels), so its jobs run on its
 * tables, in its language and with its settings.
 */
final readonly class NetworkJobSites implements JobSites
{
    private const int CHORE_SECONDS = 10;

    public function __construct(
        private SiteUpgrades $upgrades,
        private NetworkUpgrades $network,
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

    public function chores(): void
    {
        // A batch of the network's walk after an update, short so the queues are not kept waiting.
        $this->network->start();
        $this->network->step(self::CHORE_SECONDS);
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
