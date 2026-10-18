<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Storefront;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Platform\WordPress\Translations;
use OrillaCart\Shared\Http\Routing\Route;

/**
 * The storefront script and stylesheet (built from assets/storefront), which the shop's blocks and product pages
 * load, and the editor script that puts the blocks in the inserter (assets/admin/blocks).
 */
final readonly class StorefrontScripts implements Registrar
{
    public const string HANDLE = 'orillacart-storefront';
    public const string EDITOR = 'orillacart-blocks';

    public function __construct(private PluginContext $context) {}

    public function register(): void
    {
        // Before blocks register on init (priority 10), since they name these handles.
        add_action('init', $this->registerAssets(...), 5);
        add_action('wp_enqueue_scripts', $this->enqueue(...));
    }

    public function registerAssets(): void
    {
        $storefront = $this->asset('build/storefront/storefront');
        wp_register_script(self::HANDLE, $this->url('build/storefront/storefront.js'), $storefront['dependencies'], $storefront['version'], ['in_footer' => true, 'strategy' => 'defer']);
        wp_set_script_translations(self::HANDLE, Translations::DOMAIN, $this->context->path(Translations::FOLDER));
        wp_register_style(self::HANDLE, plugins_url('build/storefront/storefront.css', $this->context->pluginFile), [], $storefront['version']);
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');

        $editor = $this->asset('build/admin/blocks');
        wp_register_script(self::EDITOR, $this->url('build/admin/blocks.js'), $editor['dependencies'], $editor['version'], ['in_footer' => true]);
        wp_set_script_translations(self::EDITOR, Translations::DOMAIN, $this->context->path(Translations::FOLDER));
    }

    /**
     * Product pages load the storefront too (their buying form is not a block), and every page gets the
     * address of the REST API.
     *
     * Visitors' pages carry no nonce, since page caches keep them and would serve an expired one: the script
     * asks GET /session for one, never cached, before its first change. Logged-in users' pages carry theirs:
     * caches do not keep those pages, and the REST API ignores login cookies on a request without a nonce,
     * so /session could only give them a visitor's.
     */
    public function enqueue(): void
    {
        if (is_singular(ProductPostType::NAME)) {
            wp_enqueue_script(self::HANDLE);
            wp_enqueue_style(self::HANDLE);
        }
        wp_add_inline_script(self::HANDLE, 'window.orillacartStorefront = ' . wp_json_encode([
            'api' => rest_url('orillacart/v1'),
            'nonce' => is_user_logged_in() ? wp_create_nonce(Route::REST_NONCE) : null,
        ]) . ';', 'before');
    }

    /**
     * @return non-empty-string
     */
    private function url(string $path): string
    {
        $url = plugins_url($path, $this->context->pluginFile);

        return $url === '' ? '/' . $path : $url;
    }

    /**
     * @return array{dependencies: list<non-empty-string>, version: string}
     */
    private function asset(string $path): array
    {
        $file = $this->context->path($path . '.asset.php');
        $meta = is_file($file) ? require $file : [];
        $dependencies = [];
        foreach (\is_array($meta) && \is_array($meta['dependencies'] ?? null) ? $meta['dependencies'] : [] as $handle) {
            if (\is_string($handle) && $handle !== '') {
                $dependencies[] = $handle;
            }
        }

        return [
            'dependencies' => $dependencies,
            'version' => \is_array($meta) && \is_string($meta['version'] ?? null) ? $meta['version'] : $this->context->version,
        ];
    }
}
