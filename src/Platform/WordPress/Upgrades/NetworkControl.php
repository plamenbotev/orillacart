<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Upgrades;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Translations;

/**
 * The network's side of an update, on the main site: it begins the walk (NetworkUpgrades), runs its batches as the
 * main site's scheduled task and tells the super admins in the network admin how it goes. It needs none of a
 * shop's services, so it runs where the main site has no shop: from the shop when it is on for the whole network,
 * or else from the bundled "OrillaCart Network" plugin (network.php).
 */
final class NetworkControl
{
    private const string RETRY = 'orillacart_retry_network_upgrade';
    /** How long a scheduled batch goes on: well within a web request's time. */
    private const int BATCH_SECONDS = 20;
    /** The failed sites the notice names. */
    private const int NAMED = 5;

    private static ?string $pluginFile = null;
    private static ?string $version = null;
    private static ?NetworkUpgrades $upgrades = null;

    /**
     * @param string $pluginFile the shop's orillacart.php
     */
    public static function boot(string $pluginFile, string $version): void
    {
        if (self::$pluginFile !== null || !is_multisite()) {
            return;
        }
        self::$pluginFile = $pluginFile;
        self::$version = $version;
        if (!is_main_site()) {
            // A shop's super admins learn how to follow the network's upgrade when nothing shows it.
            add_action('admin_notices', static fn() => self::hint());

            return;
        }
        // The notices in the network admin's language also when the shop, which loads them otherwise, is not on here.
        add_action('init', static fn() => new Translations(new PluginContext($pluginFile, $version))->load(), 0);
        // After the main site's own upgrade at 999, when the shop runs here too.
        add_action('init', static fn() => self::upgrades()->start(), 1000);
        add_action(NetworkUpgrades::HOOK, static fn() => self::upgrades()->step(self::BATCH_SECONDS));
        add_action('network_admin_notices', static fn() => self::notice());
        add_action('network_admin_edit_' . self::RETRY, static fn() => self::retry());
    }

    private static function upgrades(): NetworkUpgrades
    {
        \assert(self::$pluginFile !== null && self::$version !== null);
        if (self::$upgrades === null) {
            $context = new PluginContext(self::$pluginFile, self::$version);
            self::$upgrades = new NetworkUpgrades(new SiteUpgrades($context), $context);
        }

        return self::$upgrades;
    }

    private static function notice(): void
    {
        if (!current_user_can('manage_network_plugins')) {
            return;
        }
        $walk = self::upgrades()->walk();
        if ($walk === null) {
            return;
        }
        if ($walk->finished()) {
            if (!$walk->seen) {
                if ($walk->upgraded > 0) {
                    printf(
                        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                        esc_html(\sprintf(
                            /* translators: %d: how many shops */
                            _n('OrillaCart brought the database of %d shop of the network up to date.', 'OrillaCart brought the databases of %d shops of the network up to date.', $walk->upgraded, 'orillacart'),
                            $walk->upgraded,
                        )),
                    );
                }
                $walk->seen = true;
                self::upgrades()->save($walk);
            }

            return;
        }
        if (!$walk->walked) {
            printf(
                '<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p>%3$s <code>%4$s</code></p></div>',
                esc_html(\sprintf(
                    /* translators: 1: sites gone through, 2: sites in all, 3: shops upgraded */
                    __('OrillaCart is bringing the network\'s shops up to date: %1$d of %2$d sites gone through, %3$d shops upgraded.', 'orillacart'),
                    min($walk->checked, $walk->sites),
                    $walk->sites,
                    $walk->upgraded,
                )),
                esc_html__('It goes on in the background with the main site\'s scheduled tasks, or a worker for the network (wp orillacart jobs work --network); each shop also upgrades on its own first visit.', 'orillacart'),
                esc_html__('To do them all now:', 'orillacart'),
                esc_html('wp orillacart migrate --network'),
            );
        }
        if ($walk->failed !== []) {
            $named = '';
            foreach (\array_slice($walk->failed, 0, self::NAMED, true) as $siteId => $failure) {
                $named .= \sprintf('<li><a href="%1$s">%2$s</a>: <code>%3$s</code></li>', esc_url(get_home_url($siteId)), esc_html(get_home_url($siteId)), esc_html($failure['error']));
            }
            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong></p><ul>%2$s</ul><p><a class="button" href="%3$s">%4$s</a> %5$s <code>%6$s</code></p></div>',
                esc_html(\sprintf(
                    /* translators: %d: how many shops */
                    _n('OrillaCart could not upgrade the database of %d shop of the network.', 'OrillaCart could not upgrade the databases of %d shops of the network.', \count($walk->failed), 'orillacart'),
                    \count($walk->failed),
                )),
                $named, // escaped above
                esc_url(wp_nonce_url(network_admin_url('edit.php?action=' . self::RETRY), self::RETRY)),
                esc_html__('Try them again', 'orillacart'),
                esc_html__('or run', 'orillacart'),
                esc_html('wp orillacart migrate --network'),
            );
        }
    }

    private static function retry(): void
    {
        if (!current_user_can('manage_network_plugins')) {
            wp_die(esc_html__('You may not do this.', 'orillacart'), '', ['response' => 403]);
        }
        check_admin_referer(self::RETRY);
        self::upgrades()->retry();
        wp_safe_redirect(network_admin_url());
        exit;
    }

    private static function hint(): void
    {
        if (!is_super_admin() || self::controlled()) {
            return;
        }
        $walk = self::upgrades()->walk();
        if ($walk === null || $walk->finished()) {
            return;
        }
        printf(
            '<div class="notice notice-info"><p>%1$s <code>%2$s</code></p></div>',
            esc_html__('OrillaCart is bringing the network\'s shops up to date after an update. To follow it in the network admin, activate "OrillaCart Network" for the network; to do them all now, run', 'orillacart'),
            esc_html('wp orillacart migrate --network'),
        );
    }

    /** Whether the main site runs this: the shop on for the whole network or on the main site itself, or OrillaCart Network. */
    private static function controlled(): bool
    {
        \assert(self::$pluginFile !== null);
        if (!\function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $shop = plugin_basename(self::$pluginFile);
        if (is_plugin_active_for_network($shop) || is_plugin_active_for_network(plugin_basename(\dirname(self::$pluginFile) . '/network.php'))) {
            return true;
        }
        $main = get_blog_option(get_main_site_id(), 'active_plugins');

        return \is_array($main) && \in_array($shop, $main, true);
    }
}
