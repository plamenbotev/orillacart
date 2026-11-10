<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Packages\Application\PackageTemplates;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Sandbox\SourcePolicyInterface;
use Twig\Source;

/**
 * The scripted extensions' templates for the storefront's Twig, ahead of the theme's and the plugin's: an
 * extension's own (`x/<extension>/…`) and its overrides of the shop's. Every template it serves renders in the
 * sandbox (it is the source policy); the plugin's and the theme's keep their freedom. A source's path names the
 * extension it is from (`orillacart-extension:<extension>:<name>`), for its words (design 57). It serves only
 * `.html.twig` names: an extension's notes and other files are not templates.
 */
final class PackageTemplateLoader implements LoaderInterface, SourcePolicyInterface
{
    private const string MARK = 'orillacart-extension:';

    public function __construct(private readonly PackageTemplates $templates) {}

    public function getSourceContext(string $name): Source
    {
        $source = (self::isTemplate($name) ? $this->templates->source($name) : null) ?? throw new LoaderError(\sprintf('No extension has the template "%s".', $name));

        // The path marks the source as an extension's: the sandbox is on for it and nothing else.
        return new Source($source, $name, self::path($this->templates->owner($name) ?? '', $name));
    }

    /** The path that marks a source as the extension's. */
    public static function path(string $extension, string $name): string
    {
        return self::MARK . $extension . ':' . $name;
    }

    /** The extension a source is from, by its path; null for one of the plugin's or the theme's. */
    public static function extensionOf(string $path): ?string
    {
        if (!str_starts_with($path, self::MARK)) {
            return null;
        }
        $extension = explode(':', substr($path, \strlen(self::MARK)), 2)[0];

        return $extension === '' ? null : $extension;
    }

    public function getCacheKey(string $name): string
    {
        // The extension is part of the key: its id is compiled into the template's calls of __().
        return 'extension:' . ($this->templates->owner($name) ?? '') . ':' . $name . ':' . hash('xxh128', $this->templates->source($name) ?? '');
    }

    public function isFresh(string $name, int $time): bool
    {
        return true;
    }

    public function exists(string $name): bool
    {
        return self::isTemplate($name) && $this->templates->source($name) !== null;
    }

    /** Whether a name is one of a template: lowercase, ending in .html.twig. */
    public static function isTemplate(string $name): bool
    {
        return str_ends_with($name, '.html.twig') && $name === strtolower($name);
    }

    public function enableSandbox(Source $source): bool
    {
        return str_starts_with($source->getPath(), self::MARK);
    }
}
