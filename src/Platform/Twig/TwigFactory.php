<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Platform\PluginContext;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Builds the Twig environment for storefront templates. Templates are looked up in the child theme's
 * orillacart/ folder, then the parent theme's, then the plugin's templates/ folder, so themes override by file name.
 */
final readonly class TwigFactory
{
    public const string THEME_FOLDER = 'orillacart';
    public const string PLUGIN_FOLDER = 'templates';

    public function __construct(
        private PluginContext $context,
        private ?ShopExtension $extension = null,
    ) {}

    public function create(): Environment
    {
        $debug = \defined('WP_DEBUG') && WP_DEBUG;

        $twig = new Environment(new FilesystemLoader($this->templatePaths()), [
            'autoescape' => 'html',
            'strict_variables' => $debug,
            'debug' => $debug,
            'cache' => $this->cacheDir() ?? false,
            // Recompile a template whose file changed: themes override templates without the plugin's version changing.
            'auto_reload' => true,
        ]);
        if ($this->extension !== null) {
            $twig->addExtension($this->extension);
        }

        return $twig;
    }

    /** @return list<string> existing folders, most specific first */
    public function templatePaths(): array
    {
        $paths = [];
        if (\function_exists('get_stylesheet_directory')) {
            $paths[] = get_stylesheet_directory() . '/' . self::THEME_FOLDER;
            $paths[] = get_template_directory() . '/' . self::THEME_FOLDER;
        }
        $paths[] = $this->context->path(self::PLUGIN_FOLDER);

        return array_values(array_unique(array_filter($paths, is_dir(...))));
    }

    /**
     * Compiled templates go to wp-content/cache/orillacart/twig/<version>; without a writable folder Twig compiles in memory.
     */
    private function cacheDir(): ?string
    {
        if (!\defined('WP_CONTENT_DIR')) {
            return null;
        }
        $dir = WP_CONTENT_DIR . '/cache/orillacart/twig/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $this->context->version);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }
}
