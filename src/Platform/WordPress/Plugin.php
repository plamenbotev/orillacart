<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

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
        $kernel = new Kernel(new PluginContext($pluginFile, $version));

        register_activation_hook(
            $pluginFile,
            static fn() => self::lifecycle($kernel)->activate(),
        );
        register_deactivation_hook(
            $pluginFile,
            static fn() => self::lifecycle($kernel)->deactivate(),
        );

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
        self::lifecycle(new Kernel(new PluginContext($pluginFile, '')))->uninstall();
    }

    private static function lifecycle(Kernel $kernel): PluginLifecycle
    {
        $lifecycle = $kernel->container()->get(PluginLifecycle::class);
        \assert($lifecycle instanceof PluginLifecycle);

        return $lifecycle;
    }
}
