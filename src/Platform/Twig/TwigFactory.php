<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Packages\Application\ExtensionAssets;
use OrillaCart\Packages\Application\ExtensionTranslations;
use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Builds the Twig environment for storefront templates. Templates are looked up in the scripted extensions', then
 * the folders add-ons do not trust with PHP (extension API 24), then the child theme's orillacart/ folder, the
 * parent theme's and the plugin's templates/ folder, so themes override by file name. The extensions' and the
 * untrusted folders' templates render in the sandbox and on a budget.
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
        private ?ExtensionAssets $assets = null,
        private ?InstalledExtensions $installed = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function create(): Environment
    {
        $debug = \defined('WP_DEBUG') && WP_DEBUG;

        $loaders = [];
        if ($this->packages !== null) {
            $loaders[] = $this->packages;
        }
        $addOns = $this->addOns();
        if ($addOns !== null) {
            $loaders[] = new UntrustedTemplateLoader(fn(): array => $this->untrustedFolders($addOns));
        }
        $loaders[] = new FilesystemLoader($this->templatePaths());
        $loader = \count($loaders) === 1 ? $loaders[0] : new ChainLoader($loaders);
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
        $policy = null;
        if ($this->packages !== null || $addOns !== null) {
            // The sandbox is on for the extensions' and the untrusted folders' templates only: their sources say which.
            $policy = StorefrontSandbox::policy();
            $twig->addExtension(new SandboxExtension($policy, false, new SandboxedSources($this->packages)));
            // And on a budget: capped ranges and formats, and loop turns counted per render.
            $twig->addExtension(new TemplateBudgetExtension());
            // And what they print cleaned of what would run in the browser (design 73).
            $twig->addExtension(new ScriptFreeOutputExtension());
            if ($this->words !== null) {
                // Their __() calls read the extension's words first (design 57).
                $twig->addExtension(new ExtensionWordsExtension($this->words));
            }
            if ($this->assets !== null) {
                // Their asset() calls print the extension's scripts and stylesheets, served (design 59).
                $twig->addExtension(new ExtensionAssetsExtension($this->assets));
            }
        }

        if ($addOns !== null) {
            // Last, so the shop's filters are all known when the add-ons' are checked against them.
            $twig->addExtension(new AddOnFiltersExtension($addOns, $twig, $policy, $this->logger));
        }

        return $twig;
    }

    /** The add-ons, inside WordPress: outside it (a build, a unit test) nothing registers, and nothing is asked. */
    private function addOns(): ?InstalledExtensions
    {
        return \function_exists('do_action') ? $this->installed : null;
    }

    /**
     * The add-ons' untrusted template folders that are there (extension API 24).
     *
     * @return list<string>
     */
    private function untrustedFolders(InstalledExtensions $addOns): array
    {
        $folders = [];
        foreach ($addOns->untrustedTemplateFolders() as $provider) {
            try {
                foreach ($provider->folders() as $folder) {
                    if (\is_string($folder) && $folder !== '' && is_dir($folder)) {
                        $folders[] = rtrim($folder, '/');
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('An add-on\'s untrusted template folders failed: {message}', ['message' => $e->getMessage()]);
            }
        }

        return array_values(array_unique($folders));
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
