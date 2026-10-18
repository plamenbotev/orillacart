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
        self::flush();
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->activate();
    }

    public function deactivate(): void
    {
        $this->content->unregisterPostTypes();
        self::flush();
    }

    public function uninstall(): void {}

    private static function flush(): void
    {
        // Switched to another site of a network, the rewrite object still holds the first site's permalink
        // settings: the site builds its rules again on its next request instead.
        if (is_multisite() && ms_is_switched()) {
            delete_option('rewrite_rules');

            return;
        }
        flush_rewrite_rules();
    }
}
