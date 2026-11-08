<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Packages\Application\PluginTemplates;
use Twig\Loader\FilesystemLoader;

/** The shop's templates from the theme's and the plugin's folders, for the overrides' originals. */
final readonly class PluginTemplateFiles implements PluginTemplates
{
    public function __construct(private TwigFactory $files) {}

    public function source(string $name): ?string
    {
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
            return null;
        }
        $loader = new FilesystemLoader($this->files->templatePaths());

        return $loader->exists($name) ? $loader->getSourceContext($name)->getCode() : null;
    }

    public static function hash(string $source): string
    {
        return substr(hash('sha256', $source), 0, 16);
    }
}
