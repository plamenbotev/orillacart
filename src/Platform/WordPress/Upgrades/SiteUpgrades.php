<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Upgrades;

use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\SiteKernels;

/**
 * Brings sites' databases up to the files' build. Each site does its own on its first request after an update; on
 * a network NetworkUpgrades also goes through the quiet ones.
 */
final readonly class SiteUpgrades
{
    public function __construct(private PluginContext $context) {}

    /**
     * Upgrades the site the kernel was made for, once: under a database lock, so other requests go on meanwhile
     * or, with $wait, wait that many seconds for it. A failure is kept for the admin screens to show.
     *
     * @param bool $force run the migrations even when the site says it is at the build, as WP-CLI does
     */
    public function upgradeHere(Kernel $kernel, bool $force = false, int $wait = 0): UpgradeOutcome
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
        return $stored === $this->context->build && $pending === [] ? UpgradeOutcome::current() : UpgradeOutcome::upgraded($pending);
    }

    /**
     * Upgrades another site of the network, or this one, in a container of its own.
     */
    public function upgradeSite(int $siteId, bool $force = false, int $wait = 0): UpgradeOutcome
    {
        if (is_multisite() && get_site($siteId) === null) {
            return UpgradeOutcome::noShop();
        }

        return SiteKernels::run($siteId, $this->context, function (Kernel $kernel) use ($siteId, $force, $wait): UpgradeOutcome {
            // Set up at its first request where the plugin is on, as a web request would: not skipped for having none yet.
            if (get_option(PluginLifecycle::VERSION_OPTION) === false && !$this->activeOn($siteId)) {
                return UpgradeOutcome::noShop();
            }

            return $this->upgradeHere($kernel, $force, $wait);
        });
    }

    /**
     * The sites that run the shop, with the build their tables are at: every site of the network where the plugin
     * is on, network-wide or there. One the plugin is on but that was never set up (no request reached it since)
     * is at no build, "", and so behind.
     *
     * @return array<int, string> by site id, in order
     */
    public function shops(): array
    {
        if (!is_multisite()) {
            $stored = get_option(PluginLifecycle::VERSION_OPTION);

            return \is_string($stored) ? [get_current_blog_id() => $stored] : [];
        }
        $shops = [];
        foreach (get_sites(['fields' => 'ids', 'number' => 0, 'deleted' => 0, 'orderby' => 'id']) as $siteId) {
            $siteId = (int) $siteId;
            if ($this->runsShop($siteId)) {
                $stored = get_blog_option($siteId, PluginLifecycle::VERSION_OPTION);
                $shops[$siteId] = \is_string($stored) ? $stored : '';
            }
        }

        return $shops;
    }

    /** Whether a site of the network has the plugin on, network-wide or there. */
    public function runsShop(int $siteId): bool
    {
        $site = get_site($siteId);
        if ($site === null || $site->deleted === '1') {
            return false;
        }

        return $this->activeOn($siteId);
    }

    /** Whether the plugin is on for the site: for the whole network, or there. */
    private function activeOn(int $siteId): bool
    {
        if ($this->activeForNetwork()) {
            return true;
        }
        $active = get_blog_option($siteId, 'active_plugins');

        return \is_array($active) && \in_array(plugin_basename($this->context->pluginFile), $active, true);
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
