<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Lifecycle;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
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
    ) {}

    public function activate(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->activate();
        }
        update_option(self::VERSION_OPTION, $this->context->version);
    }

    public function upgradeIfNeeded(): void
    {
        $stored = get_option(self::VERSION_OPTION);
        $stored = \is_string($stored) ? $stored : null;

        if ($stored === $this->context->version) {
            return;
        }

        foreach ($this->listeners as $listener) {
            $listener->upgrade($stored);
        }
        update_option(self::VERSION_OPTION, $this->context->version);
    }

    public function deactivate(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->deactivate();
        }
    }

    public function uninstall(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->uninstall();
        }
        delete_option(self::VERSION_OPTION);
        delete_option(self::UPGRADE_FAILED_OPTION);
    }
}
