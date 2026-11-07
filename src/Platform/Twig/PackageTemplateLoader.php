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
 * sandbox (it is the source policy); the plugin's and the theme's keep their freedom.
 */
final class PackageTemplateLoader implements LoaderInterface, SourcePolicyInterface
{
    private const string MARK = 'orillacart-extension:';

    public function __construct(private readonly PackageTemplates $templates) {}

    public function getSourceContext(string $name): Source
    {
        $source = $this->templates->source($name) ?? throw new LoaderError(\sprintf('No extension has the template "%s".', $name));

        // The path marks the source as an extension's: the sandbox is on for it and nothing else.
        return new Source($source, $name, self::MARK . $name);
    }

    public function getCacheKey(string $name): string
    {
        return 'extension:' . $name . ':' . hash('xxh128', $this->templates->source($name) ?? '');
    }

    public function isFresh(string $name, int $time): bool
    {
        return true;
    }

    public function exists(string $name): bool
    {
        return $this->templates->source($name) !== null;
    }

    public function enableSandbox(Source $source): bool
    {
        return str_starts_with($source->getPath(), self::MARK);
    }
}
