<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\AfterResponse\ShutdownWork;
use OrillaCart\Shared\Lifecycle\AfterResponse;
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
 * otherwise (forHook()): site 1's product with the id of site B's deleted post stays. Whether a site is passed
 * over is asked once per process, and only the few sites' containers used last are kept (KEPT_KERNELS): a loop
 * over a large network's sites does not hold a container for each.
 *
 * Work a step's container defers to the end of the request (AfterResponse) runs at the end of the step, on its
 * site: once the step is over the process is no longer there, and nothing else would run it. Its container says
 * it does not detach meanwhile, so the background jobs a step pushes are left to cron and workers rather than
 * run before the step ends.
 */
final class SiteKernels
{
    /** @var list<ContainerInterface> the containers of the steps under way, innermost last */
    private static array $current = [];
    /** How many sites' kernels hooks ran on are kept: the ones used last. */
    public const int KEPT_KERNELS = 4;
    /** Why a hook was passed over on a site: the shop does not run there, or its tables are behind the files. */
    public const string OFF = 'off';
    public const string BEHIND = 'behind';
    /** @var array<int, Kernel> the kernels hooks ran on, by site, the one used last last */
    private static array $hookKernels = [];
    /** @var array<int, string> why hooks are passed over on a site, by site, asked once per process */
    private static array $passedOver = [];
    /** @var array<string, true> the sites and reasons a passing over was told of, once per process */
    private static array $told = [];
    /** @var array<int, true> the sites whose container a hook is making now */
    private static array $making = [];
    /** @var (\Closure(): int)|null the site the process is on, when a test says */
    private static ?\Closure $site = null;
    /** @var (\Closure(int): (ContainerInterface|string|null))|null the containers of the sites, when a test says */
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
            if ($kernel->siteId() !== $siteId) {
                throw new \LogicException(\sprintf('The container made for site %d is site %s\'s.', $siteId, var_export($kernel->siteId(), true)));
            }

            return self::step($container, static fn(): mixed => $step($kernel));
        } finally {
            // A step may have activated or upgraded the shop there: hooks ask again.
            unset(self::$passedOver[$siteId], self::$hookKernels[$siteId]);
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

    /**
     * Runs a step as the container's site, then the work it deferred to the end of the request (AfterResponse):
     * a container made for a step never sees that end on its site.
     *
     * @template T
     *
     * @param \Closure(): T $step
     *
     * @return T
     */
    private static function step(ContainerInterface $container, \Closure $step): mixed
    {
        $deferred = $container->has(AfterResponse::class) ? $container->get(AfterResponse::class) : null;
        if ($deferred instanceof ShutdownWork) {
            $deferred->forSteps();
        }
        try {
            return self::within($container, $step);
        } finally {
            if ($deferred instanceof ShutdownWork && !\in_array($container, self::$current, true)) {
                self::within($container, $deferred->runNow(...));
            }
        }
    }

    /** Whether hooks passed over on the site for the reason are yet to be told of: true the first time only. */
    public static function firstTold(int $siteId, string $reason): bool
    {
        if (isset(self::$told["{$siteId}:{$reason}"])) {
            return false;
        }
        self::$told["{$siteId}:{$reason}"] = true;

        return true;
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
     * would come from; $skipped answers instead when the shop does not run there (OFF), its tables are behind the
     * files' build (BEHIND), or the site's container is being made (a hook firing while it is made: null).
     *
     * @template T
     *
     * @param \Closure(ContainerInterface): T $step
     * @param \Closure(?string): T            $skipped told why: OFF, BEHIND, or null while the container is made
     *
     * @return T
     */
    public static function forHook(int $siteId, ?PluginContext $context, \Closure $step, \Closure $skipped): mixed
    {
        if (isset(self::$making[$siteId])) {
            return $skipped(null);
        }
        self::$making[$siteId] = true;
        try {
            $container = self::$containers !== null ? (self::$containers)($siteId) : self::hookContainer($siteId, $context);
        } finally {
            unset(self::$making[$siteId]);
        }
        if (!$container instanceof ContainerInterface) {
            return $skipped($container ?? self::OFF);
        }

        return self::step($container, static fn(): mixed => $step($container));
    }

    /**
     * For tests: the site the process is on, and each site's container (null where the shop does not run); null
     * for WordPress's.
     *
     * @param (\Closure(): int)|null                                  $site
     * @param (\Closure(int): (ContainerInterface|string|null))|null $containers a reason (OFF, BEHIND) for none
     */
    public static function pretend(?\Closure $site, ?\Closure $containers): void
    {
        self::$site = $site;
        self::$containers = $containers;
    }

    /**
     * The container of the site the process is on, for a hook; why not (OFF, BEHIND) when the shop is not to run
     * there, which is asked once per process. Only the KEPT_KERNELS kernels used last are kept.
     */
    private static function hookContainer(int $siteId, ?PluginContext $context): ContainerInterface|string
    {
        if ($context === null || !is_multisite() || $siteId !== get_current_blog_id()) {
            return self::OFF;
        }
        if (isset(self::$passedOver[$siteId])) {
            return self::$passedOver[$siteId];
        }
        $kernel = self::$hookKernels[$siteId] ?? null;
        if ($kernel === null) {
            $own = plugin_basename($context->pluginFile);
            $network = get_site_option('active_sitewide_plugins');
            $active = get_option('active_plugins');
            $on = (\is_array($network) && isset($network[$own])) || (\is_array($active) && \in_array($own, $active, true));
            if (!$on || get_option(PluginLifecycle::VERSION_OPTION) !== $context->build) {
                return self::$passedOver[$siteId] = $on ? self::BEHIND : self::OFF;
            }
            $kernel = new Kernel($context);
        }
        $container = $kernel->container();
        if ($kernel->siteId() !== $siteId) {
            // Made on another site than the process is on: its tables are not this site's.
            unset(self::$hookKernels[$siteId]);

            throw new \LogicException(\sprintf('The container made for site %d is site %s\'s.', $siteId, var_export($kernel->siteId(), true)));
        }
        self::$hookKernels = self::keep(self::$hookKernels, $siteId, $kernel, self::KEPT_KERNELS);

        return $container;
    }

    /**
     * The kept, with the one just used last and the ones used longest ago gone past $max.
     *
     * @template K of array-key
     * @template V
     *
     * @param array<K, V> $kept
     * @param K           $key
     * @param V           $value
     *
     * @return array<K, V>
     */
    public static function keep(array $kept, int|string $key, mixed $value, int $max): array
    {
        unset($kept[$key]);
        $kept[$key] = $value;

        return \array_slice($kept, -$max, null, true);
    }

    /** The container of the site a step is running as, if one is; null between steps, when the booting one is it. */
    public static function current(): ?ContainerInterface
    {
        return self::$current === [] ? null : self::$current[\count(self::$current) - 1];
    }
}
