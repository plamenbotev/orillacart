<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use Psr\Container\ContainerInterface;

/**
 * Runs a step as another site of a network. The table prefix is read when a container is made, so each site gets
 * a container of its own; all of them share WordPress's database connection.
 *
 * While a step runs, its container is the current one: WordPress's hooks were added once, by the container the
 * plugin booted with, and a hook that fires inside the step (a job's event announced as an action, phpmailer_init
 * for its mail) resolves its service from the current container instead (HookRegistrar), so the step's site's
 * tables, settings and language are what the hook's service reads and writes, not the booting site's.
 */
final class SiteKernels
{
    /** @var list<ContainerInterface> the containers of the steps under way, innermost last */
    private static array $current = [];

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
            $kernel = new Kernel($context);

            return self::within($kernel->container(), static fn(): mixed => $step($kernel));
        } finally {
            if ($switch) {
                restore_current_blog();
            }
        }
    }

    /**
     * Runs the step with the container as the current one, for hooks that fire meanwhile.
     *
     * @template T
     *
     * @param \Closure(): T $step
     *
     * @return T
     */
    public static function within(ContainerInterface $container, \Closure $step): mixed
    {
        self::$current[] = $container;
        try {
            return $step();
        } finally {
            array_pop(self::$current);
        }
    }

    /** The container of the site a step is running as, if one is; null between steps, when the booting one is it. */
    public static function current(): ?ContainerInterface
    {
        return self::$current === [] ? null : self::$current[\count(self::$current) - 1];
    }
}
