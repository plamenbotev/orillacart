<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Extensions;

use OrillaCart\Packages\Application\ExtensionAssets;
use OrillaCart\Packages\Application\ExtensionFrames;
use OrillaCart\Platform\PluginContext;

/**
 * An extension's screen in the admin, in a frame of its own (design 73): a sandboxed iframe whose document is the
 * screen's HTML, with no `allow-same-origin`, so its scripts have an origin of their own (no cookies, no nonce,
 * nothing of the admin's page in reach), and a Content-Security-Policy that runs only the shop's bridge and the
 * extension's files and connects nowhere. The bridge (`window.orillacart`, assets/admin/extension-frame/bridge.ts)
 * is the screen's way out, by messages the admin's side (extension-frame/index.ts) checks one by one.
 */
final readonly class WordPressExtensionFrames implements ExtensionFrames
{
    /** The admin's side of the frames, which answers their messages. */
    public const string HOST = 'orillacart-extension-frame';
    /** WordPress's admin styles the screen wears, by handle. */
    private const array STYLES = ['dashicons', 'buttons', 'forms', 'common', 'list-tables', 'edit'];

    public function __construct(private PluginContext $context, private ExtensionAssets $assets) {}

    public function enqueue(): void
    {
        $meta = $this->meta('build/admin/extension-frame');
        wp_enqueue_script(self::HOST, plugins_url('build/admin/extension-frame.js', $this->context->pluginFile), $meta['dependencies'], $meta['version'], ['in_footer' => true]);
        wp_add_inline_script(self::HOST, 'window.orillacartFrames = ' . wp_json_encode(['admin' => admin_url()]) . ';', 'before');
        wp_enqueue_media();
    }

    public function render(string $extension, string $html, string $title): string
    {
        $document = $this->document($extension, $html);

        return \sprintf(
            '<iframe class="orillacart-extension-frame" data-orillacart-frame data-extension="%s" sandbox="allow-scripts" referrerpolicy="no-referrer" title="%s" style="display:block;width:100%%;border:0;min-height:120px" srcdoc="%s"></iframe>',
            esc_attr($extension),
            esc_attr($title),
            htmlspecialchars($document, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8'),
        );
    }

    public function document(string $extension, string $html): string
    {
        $folder = $this->assets->folderUrl($extension);
        $bridge = plugins_url('build/admin/extension-bridge.js', $this->context->pluginFile) . '?ver=' . rawurlencode($this->meta('build/admin/extension-bridge')['version']);
        $site = self::origin(home_url('/'));
        // The uploads may be another host's (a CDN); named once when they are the site's.
        $files = self::origin($folder) === $site ? '' : ' ' . self::origin($folder);
        $policy = implode('; ', [
            "default-src 'none'",
            'script-src ' . self::source($bridge) . ' ' . $folder,
            "style-src {$site}{$files} 'unsafe-inline'",
            "img-src {$site}{$files} data: blob:",
            "font-src {$site}{$files} data:",
            "media-src {$site}{$files}",
            "connect-src 'none'",
            "form-action 'none'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
        ]);
        $styles = '';
        foreach (self::STYLES as $handle) {
            $url = self::styleUrl($handle);
            if ($url !== null) {
                $styles .= \sprintf('<link rel="stylesheet" href="%s">', esc_url($url));
            }
        }
        return '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="' . esc_attr($policy) . '">'
            . $styles
            . '<style>html,body{margin:0;padding:0;background:transparent;overflow:hidden}body{padding:1px 0}</style>'
            . '<script src="' . esc_url($bridge) . '"></script>'
            . '</head><body class="wp-core-ui">' . $html . '</body></html>';
    }

    /** A URL as a policy names one file: no query string, which a policy never matches against. */
    private static function source(string $url): string
    {
        return explode('?', $url, 2)[0];
    }

    /** scheme://host[:port] of a URL. */
    private static function origin(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return "'none'";
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** The full URL of one of WordPress's registered stylesheets, as the admin loads it. */
    private static function styleUrl(string $handle): ?string
    {
        $styles = wp_styles();
        $style = $styles->registered[$handle] ?? null;
        $src = $style?->src;
        if (!\is_string($src) || $src === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//#', $src) !== 1) {
            $src = $styles->base_url . $src;
        }
        $version = \is_string($style->ver) ? $style->ver : get_bloginfo('version');

        return add_query_arg('ver', $version, $src);
    }

    /**
     * @return array{dependencies: list<non-empty-string>, version: string}
     */
    private function meta(string $path): array
    {
        $file = $this->context->path($path . '.asset.php');
        $meta = is_file($file) ? require $file : [];
        $dependencies = [];
        foreach (\is_array($meta) && \is_array($meta['dependencies'] ?? null) ? $meta['dependencies'] : [] as $handle) {
            if (\is_string($handle) && $handle !== '') {
                $dependencies[] = $handle;
            }
        }

        return ['dependencies' => $dependencies, 'version' => \is_array($meta) && \is_string($meta['version'] ?? null) ? $meta['version'] : $this->context->version];
    }
}
