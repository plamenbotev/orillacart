<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\PluginContext;

/**
 * Loads an admin script built by @wordpress/scripts, with the dependencies and version its .asset.php lists.
 */
final readonly class AdminScripts
{
    public function __construct(private PluginContext $context) {}

    public function enqueue(string $script): void
    {
        $handle = 'orillacart-' . $script;
        $asset = $this->context->path('build/admin/' . $script . '.asset.php');
        $meta = is_file($asset) ? require $asset : [];
        $dependencies = \is_array($meta) && \is_array($meta['dependencies'] ?? null) ? array_values(array_filter($meta['dependencies'], static fn(mixed $handle): bool => \is_string($handle) && $handle !== '')) : [];
        $version = \is_array($meta) && \is_string($meta['version'] ?? null) ? $meta['version'] : $this->context->version;

        wp_enqueue_script($handle, plugins_url('build/admin/' . $script . '.js', $this->context->pluginFile), $dependencies, $version, ['in_footer' => true]);
        wp_set_script_translations($handle, 'orillacart');
        wp_enqueue_style('wp-components');
    }
}
