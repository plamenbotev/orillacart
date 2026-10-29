<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Translations\Application\SyncTranslationPackages;

/**
 * WPML String Translation was turned on: the shop's objects are registered as packages for its translators.
 */
#[AsHook('activated_plugin', method: 'activated')]
final readonly class WpmlArrived
{
    public function __construct(private SyncTranslationPackages $sync) {}

    public function activated(string $plugin): void
    {
        if (str_starts_with($plugin, 'wpml-string-translation/')) {
            $this->sync->registerAll();
        }
    }
}
