<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Translations\Application\SyncTranslationPackages;

/**
 * Registers every object with the translation service when the plugin is activated or updated, so a shop that
 * had them before (or before WPML) gets them to translators.
 */
final readonly class TranslationPackagesLifecycle implements LifecycleListener
{
    public function __construct(private SyncTranslationPackages $sync) {}

    public function activate(): void
    {
        $this->sync->registerAll();
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->sync->registerAll();
    }

    public function deactivate(): void {}

    public function uninstall(): void {}
}
