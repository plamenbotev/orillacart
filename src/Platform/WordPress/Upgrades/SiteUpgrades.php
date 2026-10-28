<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Upgrades;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\SiteKernels;

/**
 * Brings sites' databases up to the files' build. Each site does its own on its first request after an update;
 * on a network the coordinating site (the main site, or the first site with the shop when the main site has none)
 * also queues a job for every other shop still behind, so quiet sites do not wait for a visitor.
 */
final readonly class SiteUpgrades
{
    /** Network option: the walk queued for a build, {build, coordinator, sites, at}. */
    public const string NETWORK_OPTION = 'orillacart_network_upgrade';

    public function __construct(private PluginContext $context) {}

    /**
     * Upgrades the site the kernel was made for, once: under a database lock, so other requests go on meanwhile
     * or, with $wait, wait that many seconds for it. A failure is kept for the admin screens to show.
     *
     * @param bool $force run the migrations even when the site says it is at the build, as WP-CLI does
     * @param bool $walk  queue the network's other sites when this one coordinates them
     */
    public function upgradeHere(Kernel $kernel, bool $force = false, int $wait = 0, bool $walk = true): UpgradeOutcome
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        \assert($wpdb instanceof \wpdb);
        $lock = 'orillacart_upgrade_' . $wpdb->prefix;
        if ($wpdb->get_var((string) $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, $wait)) !== '1') {
            return UpgradeOutcome::busy();
        }
        try {
            // Read from the table, not the option cache: the request that held the lock may have just finished.
            $stored = $wpdb->get_var((string) $wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, PluginLifecycle::VERSION_OPTION));
            if ($stored === $this->context->build && !$force) {
                wp_cache_delete('alloptions', 'options');

                return UpgradeOutcome::current();
            }
            if (\function_exists('set_time_limit')) {
                set_time_limit(0);
            }
            ignore_user_abort(true);
            $migrator = $kernel->container()->get(DatabaseMigrator::class);
            \assert($migrator instanceof DatabaseMigrator);
            $pending = $migrator->pending();
            if ($force) {
                $migrator->migrate();
            }
            $lifecycle = $kernel->container()->get(PluginLifecycle::class);
            \assert($lifecycle instanceof PluginLifecycle);
            $lifecycle->upgradeIfNeeded();
            delete_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
        } catch (\Throwable $e) {
            update_option(PluginLifecycle::UPGRADE_FAILED_OPTION, ['version' => $this->context->build, 'message' => $e->getMessage(), 'at' => time()], false);
            error_log(\sprintf('[OrillaCart] ERROR: The database could not be upgraded to %s: %s', $this->context->build, $e));

            return UpgradeOutcome::failed($e->getMessage());
        } finally {
            $wpdb->query((string) $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        if ($walk) {
            try {
                $this->walk($kernel);
            } catch (\Throwable $e) {
                // The other sites still upgrade on their own requests.
                error_log(\sprintf('[OrillaCart] ERROR: The network\'s other shops could not be queued for the upgrade to %s: %s', $this->context->build, $e));
            }
        }

        return $stored === $this->context->build && $pending === [] ? UpgradeOutcome::current() : UpgradeOutcome::upgraded($pending);
    }

    /**
     * Upgrades another site of the network, or this one, in a container of its own.
     */
    public function upgradeSite(int $siteId, bool $force = false, int $wait = 0, bool $walk = true): UpgradeOutcome
    {
        if (is_multisite() && get_site($siteId) === null) {
            return UpgradeOutcome::noShop();
        }

        return SiteKernels::run($siteId, $this->context, function (Kernel $kernel) use ($force, $wait, $walk): UpgradeOutcome {
            if (get_option(PluginLifecycle::VERSION_OPTION) === false) {
                return UpgradeOutcome::noShop();
            }

            return $this->upgradeHere($kernel, $force, $wait, $walk);
        });
    }

    /**
     * The sites that run the shop, with the build their tables are at: every site of the network where the plugin
     * is on, network-wide or there, and that was set up.
     *
     * @return array<int, string> by site id, in order
     */
    public function shops(): array
    {
        if (!is_multisite()) {
            $stored = get_option(PluginLifecycle::VERSION_OPTION);

            return \is_string($stored) ? [get_current_blog_id() => $stored] : [];
        }
        $network = $this->activeForNetwork();
        $basename = plugin_basename($this->context->pluginFile);
        $shops = [];
        foreach (get_sites(['fields' => 'ids', 'number' => 0, 'deleted' => 0, 'orderby' => 'id']) as $siteId) {
            $siteId = (int) $siteId;
            if (!$network) {
                $active = get_blog_option($siteId, 'active_plugins');
                if (!\is_array($active) || !\in_array($basename, $active, true)) {
                    continue;
                }
            }
            $stored = get_blog_option($siteId, PluginLifecycle::VERSION_OPTION);
            if (\is_string($stored)) {
                $shops[$siteId] = $stored;
            }
        }

        return $shops;
    }

    /**
     * The shops whose tables are not at the files' build.
     *
     * @return list<int>
     */
    public function behind(): array
    {
        return array_keys(array_filter($this->shops(), fn(string $stored): bool => $stored !== $this->context->build));
    }

    /** The site whose queue upgrades the network's others: the main site, or the first shop when it has none. */
    public function coordinator(): ?int
    {
        $main = get_main_site_id();
        if ($this->activeForNetwork()) {
            return $main;
        }
        $shops = array_keys($this->shops());
        if ($shops === []) {
            return null;
        }

        return \in_array($main, $shops, true) ? $main : min($shops);
    }

    /**
     * The walk queued for the files' build, if any.
     *
     * @return array{coordinator: int, sites: int}|null
     */
    public function walking(): ?array
    {
        $walk = get_site_option(self::NETWORK_OPTION);
        if (!\is_array($walk) || ($walk['build'] ?? null) !== $this->context->build || !\is_int($walk['coordinator'] ?? null) || !\is_int($walk['sites'] ?? null)) {
            return null;
        }

        return ['coordinator' => $walk['coordinator'], 'sites' => $walk['sites']];
    }

    /**
     * Deletes the coordinator's failed upgrade jobs whose site is at the files' build now, upgraded since by a
     * visitor or WP-CLI: nothing is left for them to do.
     *
     * @return int how many are still failed
     */
    public function forgetSettledFailures(int $coordinator): int
    {
        return SiteKernels::run($coordinator, $this->context, function (Kernel $kernel): int {
            $jobs = $kernel->container()->get(Jobs::class);
            \assert($jobs instanceof Jobs);
            $failed = $jobs->failed(UpgradeSites::TYPE, 1000);
            $settled = [];
            foreach ($failed as $job) {
                $site = $job->payload['site'] ?? null;
                if (!\is_int($site) || get_site($site) === null || get_blog_option($site, PluginLifecycle::VERSION_OPTION) === $this->context->build) {
                    $settled[] = $job->id;
                }
            }
            if ($settled !== []) {
                $jobs->forgetFailed($settled);
            }

            return \count($failed) - \count($settled);
        });
    }

    /** The walk is over: every site it queued is upgraded, or its job was deleted. */
    public function walked(): void
    {
        delete_site_option(self::NETWORK_OPTION);
    }

    /**
     * When this site coordinates a network, queues a job for every other shop still behind, once for each build.
     */
    private function walk(Kernel $kernel): void
    {
        if (!is_multisite() || $this->coordinator() !== get_current_blog_id()) {
            return;
        }
        $walk = get_site_option(self::NETWORK_OPTION);
        if (\is_array($walk) && ($walk['build'] ?? null) === $this->context->build) {
            return;
        }
        $here = get_current_blog_id();
        $sites = array_values(array_filter($this->behind(), static fn(int $siteId): bool => $siteId !== $here));
        if ($sites === []) {
            $this->walked();

            return;
        }
        $jobs = $kernel->container()->get(Jobs::class);
        \assert($jobs instanceof Jobs);
        $jobs->pushMany(UpgradeSites::TYPE, array_map(fn(int $siteId): array => ['key' => 'site:' . $siteId, 'payload' => ['site' => $siteId, 'build' => $this->context->build]], $sites));
        update_site_option(self::NETWORK_OPTION, ['build' => $this->context->build, 'coordinator' => $here, 'sites' => \count($sites), 'at' => time()]);
    }

    private function activeForNetwork(): bool
    {
        if (!is_multisite()) {
            return false;
        }
        if (!\function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename($this->context->pluginFile));
    }
}
