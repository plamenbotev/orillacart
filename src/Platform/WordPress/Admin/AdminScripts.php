<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Translations;
use OrillaCart\Settings\Application\SettingsService;

/**
 * Loads an admin script built by @wordpress/scripts, with the dependencies and version its .asset.php lists,
 * and the stylesheet built from the styles it imports.
 */
final readonly class AdminScripts
{
    public function __construct(
        private PluginContext $context,
        private SettingsService $settings,
    ) {}

    public function enqueue(string $script): void
    {
        $handle = $this->load($script);
        $settings = $this->settings->current();
        // How the shop shows amounts, for the screens' own formatting (orders/labels.ts): as the storefront does,
        // with the separators of the site's language.
        $locale = $GLOBALS['wp_locale'] ?? null;
        $format = $locale instanceof \WP_Locale ? $locale->number_format : [];
        wp_add_inline_script($handle, 'window.orillacartMoney = ' . wp_json_encode([
            'currency' => $settings->currency,
            'sign' => $settings->currencySign,
            'position' => $settings->currencyPosition,
            'decimals' => $settings->priceDecimals,
            'thousands' => \is_string($format['thousands_sep'] ?? null) ? $format['thousands_sep'] : ',',
            'point' => \is_string($format['decimal_point'] ?? null) ? $format['decimal_point'] : '.',
        ]) . ';', 'before');
        // The shop's time zone, in which dates are entered and shown (shared/dates.ts): a name or an offset.
        wp_add_inline_script($handle, 'window.orillacartTimeZone = ' . wp_json_encode(wp_timezone_string()) . ';', 'before');
        // Where exports download from (shared/ExportDialog.tsx), with the nonce that lets the shop manager.
        wp_add_inline_script($handle, 'window.orillacartExport = ' . wp_json_encode(['url' => admin_url('admin-post.php'), 'nonce' => wp_create_nonce('wp_rest')]) . ';', 'before');
        wp_enqueue_script($handle);
        wp_enqueue_style('wp-components');
        if (wp_style_is($handle, 'registered')) {
            wp_enqueue_style($handle);
        }
    }

    /**
     * Registers the script and the styles it imports, and first the plugin's own scripts it needs, such as
     * the shared DataViews (handle orillacart-dataviews, built as build/admin/dataviews.js).
     *
     * @return non-empty-string the script's handle
     */
    private function load(string $script): string
    {
        $handle = 'orillacart-' . $script;
        if (wp_script_is($handle, 'registered')) {
            return $handle;
        }
        $asset = $this->context->path('build/admin/' . $script . '.asset.php');
        $meta = is_file($asset) ? require $asset : [];
        $dependencies = \is_array($meta) && \is_array($meta['dependencies'] ?? null) ? array_values(array_filter($meta['dependencies'], static fn(mixed $handle): bool => \is_string($handle) && $handle !== '')) : [];
        $version = \is_array($meta) && \is_string($meta['version'] ?? null) ? $meta['version'] : $this->context->version;

        $styles = ['wp-components'];
        foreach ($dependencies as $dependency) {
            if (str_starts_with($dependency, 'orillacart-')) {
                $this->load(substr($dependency, \strlen('orillacart-')));
                if (wp_style_is($dependency, 'registered')) {
                    $styles[] = $dependency;
                }
            }
        }

        $url = plugins_url('build/admin/' . $script . '.js', $this->context->pluginFile);
        wp_register_script($handle, $url === '' ? false : $url, $dependencies, $version, ['in_footer' => true]);
        wp_set_script_translations($handle, Translations::DOMAIN, $this->context->path(Translations::FOLDER));
        // Styles the script imports are built next to it.
        if (is_file($this->context->path('build/admin/' . $script . '.css'))) {
            wp_register_style($handle, plugins_url('build/admin/' . $script . '.css', $this->context->pluginFile), $styles, $version);
            wp_style_add_data($handle, 'rtl', 'replace');
        } elseif (\count($styles) > 1) {
            // No styles of its own, but those of the scripts it needs.
            wp_register_style($handle, false, $styles, $version);
        }

        return $handle;
    }
}
