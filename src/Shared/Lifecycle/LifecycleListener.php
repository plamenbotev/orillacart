<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Lifecycle;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Reacts to the plugin being installed, updated or removed.
 * Implementations are found by the container through this tag.
 */
#[AutoconfigureTag(self::TAG)]
interface LifecycleListener
{
    public const string TAG = 'orillacart.lifecycle_listener';

    public function activate(): void;

    /** The plugin code changed since the last run (after an update), from the version stored then. */
    public function upgrade(?string $fromVersion): void;

    public function deactivate(): void;

    /** The plugin is being deleted: remove everything it created. */
    public function uninstall(): void;
}
