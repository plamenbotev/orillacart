<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Packages\Application\ExtensionTranslations;
use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ChainLoader;
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
        private ?PackageTemplateLoader $packages = null,
        private ?ExtensionTranslations $words = null,
    ) {}

    public function create(): Environment
    {
        $debug = \defined('WP_DEBUG') && WP_DEBUG;

        $loader = $this->packages === null ? new FilesystemLoader($this->templatePaths()) : new ChainLoader([$this->packages, new FilesystemLoader($this->templatePaths())]);
        $twig = new Environment($loader, [
            'autoescape' => 'html',
            // Strict everywhere: on a live site TwigRenderer logs what strictness finds and renders leniently.
            'strict_variables' => true,
            'debug' => $debug,
            'cache' => $this->cacheDir() ?? false,
            // Recompile a template whose file changed: themes override templates without the plugin's version changing.
            'auto_reload' => true,
        ]);
        if ($this->extension !== null) {
            $twig->addExtension($this->extension);
        }
        // The storefront's slots are open to every template here; the email environment keeps them to email_.
        $twig->addGlobal(ShopExtension::STOREFRONT, true);
        if ($this->packages !== null) {
            // The sandbox is on for the extensions' templates only: the loader is the source policy that says which.
            $twig->addExtension(new SandboxExtension(StorefrontSandbox::policy(), false, $this->packages));
            if ($this->words !== null) {
                // Their __() calls read the extension's words first (design 57).
                $twig->addExtension(new ExtensionWordsExtension($this->words));
            }
        }

        return $twig;
    }

    /**
     * The theme's folders, then those add-ons give on the filter orillacart_template_paths, then the plugin's.
     *
     * @return list<string> existing folders, most specific first
     */
    public function templatePaths(): array
    {
        $paths = [];
        if (\function_exists('get_stylesheet_directory')) {
            $paths[] = get_stylesheet_directory() . '/' . self::THEME_FOLDER;
            $paths[] = get_template_directory() . '/' . self::THEME_FOLDER;
        }
        if (\function_exists('apply_filters')) {
            $added = apply_filters('orillacart_template_paths', []);
            foreach (\is_array($added) ? $added : [] as $path) {
                if (\is_string($path) && $path !== '') {
                    $paths[] = rtrim($path, '/');
                }
            }
        }
        $paths[] = $this->context->path(self::PLUGIN_FOLDER);

        return array_values(array_unique(array_filter($paths, is_dir(...))));
    }

    /**
     * Compiled templates go to wp-content/cache/orillacart/twig/<version>; without a writable folder Twig compiles in memory.
     */
    private function cacheDir(): ?string
    {
        return CacheFolders::path('twig', $this->context->build);
    }
}
