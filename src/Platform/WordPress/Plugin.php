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
    private const string RETRY_UPGRADE = 'orillacart_retry_upgrade';
    /** How long a failed upgrade waits before a request tries it again by itself. */
    private const int RETRY_AFTER_SECONDS = 600;

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

        // After an update the files change without a new activation: the first request after it catches up the
        // schema, whatever kind of request it is, so the shop never runs new code on old tables for long.
        // Late on init: the upgrade's steps need post types, rewrite rules and translations.
        add_action('init', static fn() => self::upgrade($kernel, $context), 999);
        add_action('admin_notices', static fn() => self::upgradeNotice($context));
        add_action('admin_post_' . self::RETRY_UPGRADE, static fn() => self::retryUpgrade());

        $integration = $kernel->container()->get(WordPressIntegration::class);
        \assert($integration instanceof WordPressIntegration);
        $integration->register();

        if (\defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('orillacart migrate', static function () use ($kernel): void {
                $migrator = $kernel->container()->get(DatabaseMigrator::class);
                \assert($migrator instanceof DatabaseMigrator);
                $executed = $migrator->migrate();
                self::lifecycle($kernel)->upgradeIfNeeded();
                delete_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
                \WP_CLI::success($executed === [] ? 'Database is up to date.' : 'Migrated: ' . implode(', ', $executed));
            });
        }
    }

    /**
     * Brings the database up to the files' version, once: one request does it under a database lock while the
     * others go on. A failure is kept for the admin screens to show, and not tried again on every request for a
     * while, so a migration that cannot run does not slow down or break the whole site.
     */
    private static function upgrade(Kernel $kernel, PluginContext $context): void
    {
        if (get_option(PluginLifecycle::VERSION_OPTION) === $context->build) {
            return;
        }
        $failed = get_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
        if (\is_array($failed) && ($failed['version'] ?? null) === $context->build && \is_int($failed['at'] ?? null) && $failed['at'] > time() - self::RETRY_AFTER_SECONDS) {
            return;
        }
        $wpdb = $GLOBALS['wpdb'] ?? null;
        \assert($wpdb instanceof \wpdb);
        $lock = 'orillacart_upgrade_' . $wpdb->prefix;
        if ($wpdb->get_var((string) $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            // Another request is upgrading.
            return;
        }
        try {
            // Read from the table, not the option cache: the request that held the lock may have just finished.
            $stored = $wpdb->get_var((string) $wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, PluginLifecycle::VERSION_OPTION));
            if ($stored === $context->build) {
                wp_cache_delete('alloptions', 'options');

                return;
            }
            if (\function_exists('set_time_limit')) {
                set_time_limit(0);
            }
            ignore_user_abort(true);
            self::lifecycle($kernel)->upgradeIfNeeded();
            delete_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
        } catch (\Throwable $e) {
            update_option(PluginLifecycle::UPGRADE_FAILED_OPTION, ['version' => $context->build, 'message' => $e->getMessage(), 'at' => time()], false);
            error_log(\sprintf('[OrillaCart] ERROR: The database could not be upgraded to %s: %s', $context->build, $e));
        } finally {
            $wpdb->query((string) $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function upgradeNotice(PluginContext $context): void
    {
        $failed = get_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
        if (!\is_array($failed) || ($failed['version'] ?? null) !== $context->build || !current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><code>%3$s</code></p><p>%4$s <a href="%5$s">%6$s</a></p></div>',
            esc_html__('OrillaCart could not update its database.', 'orillacart'),
            esc_html__('The shop may not work until it does. The error:', 'orillacart'),
            esc_html(\is_string($failed['message'] ?? null) ? $failed['message'] : ''),
            esc_html__('Run wp orillacart migrate, or', 'orillacart'),
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::RETRY_UPGRADE), self::RETRY_UPGRADE)),
            esc_html__('try again now', 'orillacart'),
        );
    }

    private static function retryUpgrade(): void
    {
        if (!current_user_can('activate_plugins')) {
            wp_die(esc_html__('You may not do this.', 'orillacart'), 403);
        }
        check_admin_referer(self::RETRY_UPGRADE);
        // The next request tries at once; this one is past init.
        delete_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
        $back = wp_get_referer();
        wp_safe_redirect($back === false ? admin_url() : $back);
        exit;
    }

    public static function uninstall(string $pluginFile): void
    {
        // the version only matters for upgrades, which never run during uninstall
        self::onSites(true, new PluginContext($pluginFile, ''), static fn(Kernel $site) => self::lifecycle($site)->uninstall());
    }

    /**
     * A site added to a network where the plugin is on for every site is set up like the others; a deleted
     * site's shop goes with it, its tables (WordPress drops only its own) and its download files.
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
                    self::lifecycle($kernel)->uninstall(everything: true);
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
