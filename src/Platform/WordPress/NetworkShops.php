<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Upgrades\SiteUpgrades;
use OrillaCart\Shared\WordPress\OtherShops;

/**
 * The network's other shops, each run as its site (SiteKernels).
 */
final readonly class NetworkShops implements OtherShops
{
    public function __construct(
        private SiteUpgrades $upgrades,
        private PluginContext $context,
    ) {}

    public function each(\Closure $step): void
    {
        if (!is_multisite()) {
            return;
        }
        $here = get_current_blog_id();
        foreach ($this->upgrades->shops() as $siteId => $stored) {
            if ($siteId === $here) {
                continue;
            }
            SiteKernels::run($siteId, $this->context, function (Kernel $kernel) use ($stored, $step): void {
                // A site nobody visited since an update is brought up first: the step runs the new code.
                if ($stored !== $this->context->build && !$this->upgrades->upgradeHere($kernel, wait: 30)->isUpToDate()) {
                    return;
                }
                $step($kernel->container());
            });
        }
    }
}
