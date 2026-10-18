<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;

/**
 * Connects the plugin to WordPress: the only place that registers the lifecycle hooks.
 */
final class Plugin
{
    public static function boot(string $pluginFile, string $version): void
    {
        $context = new PluginContext($pluginFile, $version);
        $kernel = new Kernel($context);

        // Network-wide on a multisite, every site gets the shop: each has its own tables, pages and roles.
        register_activation_hook(
            $pluginFile,
            static fn(bool $networkWide = false) => self::onSites($networkWide, $context, static fn(Kernel $site) => self::lifecycle($site)->activate()),
        );
        register_deactivation_hook(
            $pluginFile,
            static fn(bool $networkWide = false) => self::onSites($networkWide, $context, static fn(Kernel $site) => self::lifecycle($site)->deactivate()),
        );
        if (is_multisite()) {
            self::followTheNetwork($context);
        }

        // After an update the files change without a new activation; admin requests catch up the schema.
        add_action('admin_init', static fn() => self::lifecycle($kernel)->upgradeIfNeeded());

        $integration = $kernel->container()->get(WordPressIntegration::class);
        \assert($integration instanceof WordPressIntegration);
        $integration->register();

        if (\defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('orillacart migrate', static function () use ($kernel): void {
                $migrator = $kernel->container()->get(DatabaseMigrator::class);
                \assert($migrator instanceof DatabaseMigrator);
                $executed = $migrator->migrate();
                \WP_CLI::success($executed === [] ? 'Database is up to date.' : 'Migrated: ' . implode(', ', $executed));
            });
        }
    }

    public static function uninstall(string $pluginFile): void
    {
        // the version only matters for upgrades, which never run during uninstall
        self::onSites(true, new PluginContext($pluginFile, ''), static fn(Kernel $site) => self::lifecycle($site)->uninstall());
    }

    /**
     * A site added to a network where the plugin is on for every site is set up like the others; a deleted
     * site's tables go with it (WordPress drops only its own).
     */
    private static function followTheNetwork(PluginContext $context): void
    {
        add_action('wp_initialize_site', static function (\WP_Site $site) use ($context): void {
            if (!\function_exists('is_plugin_active_for_network')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (is_plugin_active_for_network(plugin_basename($context->pluginFile))) {
                self::onSite((int) $site->blog_id, $context, static fn(Kernel $kernel) => self::lifecycle($kernel)->activate());
            }
        }, 900);
        // Before WordPress drops the site's tables at priority 10, while its options still say whether it had the shop.
        add_action('wp_uninitialize_site', static function (\WP_Site $site) use ($context): void {
            self::onSite((int) $site->blog_id, $context, static function (Kernel $kernel): void {
                if (get_option(PluginLifecycle::VERSION_OPTION) !== false) {
                    $migrator = $kernel->container()->get(DatabaseMigrator::class);
                    \assert($migrator instanceof DatabaseMigrator);
                    $migrator->uninstall();
                }
            });
        }, 5);
        // A user deleted from the network stops being a customer of every shop on it; deleted_user covers this site.
        add_action('wpmu_delete_user', static function (int $userId) use ($context): void {
            foreach (get_sites(['fields' => 'ids', 'number' => 0, 'site__not_in' => [get_current_blog_id()]]) as $siteId) {
                self::onSite((int) $siteId, $context, static function (Kernel $kernel) use ($userId): void {
                    if (get_option(PluginLifecycle::VERSION_OPTION) !== false) {
                        $customers = $kernel->container()->get(CustomerService::class);
                        \assert($customers instanceof CustomerService);
                        $customers->forget($userId);
                    }
                });
            }
        });
    }

    /**
     * Runs a step on this site, or with $network on every site of a network.
     *
     * @param \Closure(Kernel): void $step
     */
    private static function onSites(bool $network, PluginContext $context, \Closure $step): void
    {
        if (!$network || !is_multisite()) {
            $step(new Kernel($context));

            return;
        }
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            self::onSite((int) $siteId, $context, $step);
        }
    }

    /**
     * Runs a step as another site of the network. The table prefix is read when a container is made, so each
     * site gets a container of its own.
     *
     * @param \Closure(Kernel): void $step
     */
    private static function onSite(int $siteId, PluginContext $context, \Closure $step): void
    {
        switch_to_blog($siteId);
        try {
            $step(new Kernel($context));
        } finally {
            restore_current_blog();
        }
    }

    private static function lifecycle(Kernel $kernel): PluginLifecycle
    {
        $lifecycle = $kernel->container()->get(PluginLifecycle::class);
        \assert($lifecycle instanceof PluginLifecycle);

        return $lifecycle;
    }
}
