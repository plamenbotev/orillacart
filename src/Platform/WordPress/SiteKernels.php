<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
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
 *
 * WordPress and other plugins switch sites too (switch_to_blog(): deleting a network user's posts on each site, a
 * network dashboard). A shop hook that fires while the process is on another site than its container's runs on
 * that site's container, when the shop runs there and its tables are at the files' build, and is passed over
 * otherwise (forHook()): site 1's product with the id of site B's deleted post stays.
 */
final class SiteKernels
{
    /** @var list<ContainerInterface> the containers of the steps under way, innermost last */
    private static array $current = [];
    /** @var array<int, Kernel> the kernels hooks ran on, by site, kept for the process */
    private static array $hookKernels = [];
    /** @var array<int, true> the sites whose container a hook is making now */
    private static array $making = [];
    /** @var (\Closure(): int)|null the site the process is on, when a test says */
    private static ?\Closure $site = null;
    /** @var (\Closure(int): ?ContainerInterface)|null the containers of the sites, when a test says */
    private static ?\Closure $containers = null;

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
            $container = $kernel->container();
            \assert($kernel->siteId() === $siteId, 'The container is the site\'s the step runs as.');

            return self::within($container, static fn(): mixed => $step($kernel));
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

    /** The site the process is on now: WordPress's current blog, 1 on a single site. */
    public static function processSite(): int
    {
        if (self::$site !== null) {
            return (self::$site)();
        }

        return \function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
    }

    /**
     * Runs a hook's step on the container of the site the process is on, which is not the one the hook's class
     * would come from; $skipped answers instead when the shop does not run there, its tables are behind the
     * files' build, or the site's container is being made (a hook firing while it is made).
     *
     * @template T
     *
     * @param \Closure(ContainerInterface): T $step
     * @param \Closure(): T                   $skipped
     *
     * @return T
     */
    public static function forHook(int $siteId, ?PluginContext $context, \Closure $step, \Closure $skipped): mixed
    {
        if (isset(self::$making[$siteId])) {
            return $skipped();
        }
        self::$making[$siteId] = true;
        try {
            $container = self::$containers !== null ? (self::$containers)($siteId) : self::hookContainer($siteId, $context);
        } finally {
            unset(self::$making[$siteId]);
        }
        if ($container === null) {
            return $skipped();
        }

        return self::within($container, static fn(): mixed => $step($container));
    }

    /**
     * For tests: the site the process is on, and each site's container (null where the shop does not run); null
     * for WordPress's.
     *
     * @param (\Closure(): int)|null                     $site
     * @param (\Closure(int): ?ContainerInterface)|null $containers
     */
    public static function pretend(?\Closure $site, ?\Closure $containers): void
    {
        self::$site = $site;
        self::$containers = $containers;
    }

    /** The container of the site the process is on, for a hook; null when the shop is not to run there. */
    private static function hookContainer(int $siteId, ?PluginContext $context): ?ContainerInterface
    {
        if ($context === null || !is_multisite() || $siteId !== get_current_blog_id()) {
            return null;
        }
        if (!isset(self::$hookKernels[$siteId])) {
            $own = plugin_basename($context->pluginFile);
            $network = get_site_option('active_sitewide_plugins');
            $active = get_option('active_plugins');
            $on = (\is_array($network) && isset($network[$own])) || (\is_array($active) && \in_array($own, $active, true));
            if (!$on || get_option(PluginLifecycle::VERSION_OPTION) !== $context->build) {
                return null;
            }
            self::$hookKernels[$siteId] = new Kernel($context);
        }
        $kernel = self::$hookKernels[$siteId];
        $container = $kernel->container();
        \assert($kernel->siteId() === $siteId, 'The container is the site\'s the hook fires on.');

        return $container;
    }

    /** The container of the site a step is running as, if one is; null between steps, when the booting one is it. */
    public static function current(): ?ContainerInterface
    {
        return self::$current === [] ? null : self::$current[\count(self::$current) - 1];
    }
}
