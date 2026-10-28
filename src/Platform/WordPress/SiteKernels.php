<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;

/**
 * Runs a step as another site of a network. The table prefix is read when a container is made, so each site gets
 * a container of its own; all of them share WordPress's database connection.
 */
final class SiteKernels
{
    /**
     * @template T
     *
     * @param \Closure(Kernel): T $step
     *
     * @return T
     */
    public static function run(int $siteId, PluginContext $context, \Closure $step): mixed
    {
        $switch = is_multisite() && $siteId !== get_current_blog_id();
        if ($switch) {
            switch_to_blog($siteId);
        }
        try {
            return $step(new Kernel($context));
        } finally {
            if ($switch) {
                restore_current_blog();
            }
        }
    }
}
