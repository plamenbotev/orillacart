<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Lifecycle;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every lifecycle listener and remembers which plugin version last ran its upgrades.
 */
final readonly class PluginLifecycle
{
    public const string VERSION_OPTION = 'orillacart_version';
    /** Why the last upgrade failed, to which version and when: {version, message, at}. */
    public const string UPGRADE_FAILED_OPTION = 'orillacart_upgrade_failed';

    /**
     * @param iterable<LifecycleListener> $listeners
     */
    public function __construct(
        #[AutowireIterator(LifecycleListener::TAG)]
        private iterable $listeners,
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
    }

    /**
     * The plugin is deleted: what it can make again goes (its schedules, rules, caches and capabilities), and the
     * shop's data too when the shop asked for that in its settings.
     */
    /**
     * @param bool $everything whether the shop's data goes whatever the settings say: a deleted site's does, its
     *                         download files (kept outside its tables) included
     */
    public function uninstall(bool $everything = false): void
    {
        $removeData = $everything || $this->settings->load()->removeDataOnUninstall;
        foreach ($this->listeners as $listener) {
            if ($removeData || !$listener instanceof RemovesShopData) {
                $listener->uninstall();
            }
        }
        if ($removeData) {
            delete_option(self::VERSION_OPTION);
        }
        delete_option(self::UPGRADE_FAILED_OPTION);
    }
}
