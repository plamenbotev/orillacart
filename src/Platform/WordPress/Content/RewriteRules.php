<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Content;

use OrillaCart\Shared\Lifecycle\LifecycleListener;

/**
 * Refreshes the rewrite rules when the plugin is activated, updated or deactivated, so the permalinks of its
 * post types and taxonomies work, and stop working once it is off.
 */
final readonly class RewriteRules implements LifecycleListener
{
    public function __construct(private ContentRegistrar $content) {}

    public function activate(): void
    {
        $this->content->registerContent();
        flush_rewrite_rules();
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->activate();
    }

    public function deactivate(): void
    {
        $this->content->unregisterPostTypes();
        flush_rewrite_rules();
    }

    public function uninstall(): void {}
}
