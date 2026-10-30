<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Lifecycle;

use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\OutsideTheSite;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every lifecycle listener and remembers which plugin version last ran its upgrades. The migrator is not one
 * of the crowd: it runs before every other listener on activation and upgrade, so each of them finds the tables as
 * its code expects them (a listener writing a sealed secret needs the column that is wide enough for it), and
 * after every other listener on uninstall, so they can still read the tables they clean up around.
 */
final readonly class PluginLifecycle
{
    public const string VERSION_OPTION = 'orillacart_version';
    /** Why the last upgrade failed, to which version and when: {version, message, at}. */
    public const string UPGRADE_FAILED_OPTION = 'orillacart_upgrade_failed';

    /**
     * @param iterable<LifecycleListener> $listeners every listener but the migrator
     */
    public function __construct(
        #[AutowireIterator(LifecycleListener::TAG, exclude: [DatabaseMigrator::class])]
        private iterable $listeners,
        private DatabaseMigrator $migrator,
        private PluginContext $context,
        private SettingsRepository $settings,
    ) {}

    public function activate(): void
    {
        // Every migration runs now, for every site of a network turned on at once: not cut off by the host's
        // time limit halfway through one, nor by the browser leaving.
        if (\function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        ignore_user_abort(true);
        $this->migrator->activate();
        foreach ($this->listeners as $listener) {
            $listener->activate();
        }
        update_option(self::VERSION_OPTION, $this->context->build);
    }

    public function upgradeIfNeeded(): void
    {
        $stored = get_option(self::VERSION_OPTION);
        $stored = \is_string($stored) ? $stored : null;

        if ($stored === $this->context->build) {
            return;
        }

        $this->migrator->upgrade($stored);
        foreach ($this->listeners as $listener) {
            $listener->upgrade($stored);
        }
        update_option(self::VERSION_OPTION, $this->context->build);
    }

    public function deactivate(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->deactivate();
        }
        $this->migrator->deactivate();
    }

    /**
     * The plugin is deleted: what it can make again goes (its schedules, rules, caches and capabilities), and the
     * shop's data too when the shop asked for that in its settings.
     */
    public function uninstall(): void
    {
        $this->remove($this->settings->load()->removeDataOnUninstall, siteDeleted: false);
    }

    /**
     * A site of a network is being deleted: its shop goes with it, whatever its settings said, its download files
     * (kept outside its tables) included; what is the network's or WordPress drops with the site (OutsideTheSite)
     * is left alone.
     */
    public function uninstallSite(): void
    {
        $this->remove(true, siteDeleted: true);
    }

    private function remove(bool $removeData, bool $siteDeleted): void
    {
        foreach ($this->listeners as $listener) {
            if ($siteDeleted && $listener instanceof OutsideTheSite) {
                continue;
            }
            if ($removeData || !$listener instanceof RemovesShopData) {
                $listener->uninstall();
            }
        }
        if ($removeData) {
            // Last: the tables go once nothing needs to read them any more.
            $this->migrator->uninstall();
            delete_option(self::VERSION_OPTION);
        }
        delete_option(self::UPGRADE_FAILED_OPTION);
    }
}
