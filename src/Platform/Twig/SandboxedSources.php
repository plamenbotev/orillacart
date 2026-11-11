<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Sandbox\SourcePolicyInterface;
use Twig\Source;

/** The templates the storefront sandboxes: the extensions' and the untrusted folders'; the plugin's and the theme's keep their freedom. */
final readonly class SandboxedSources implements SourcePolicyInterface
{
    public function __construct(private ?PackageTemplateLoader $packages) {}

    public function enableSandbox(Source $source): bool
    {
        return UntrustedTemplateLoader::isUntrusted($source->getPath()) || ($this->packages?->enableSandbox($source) ?? false);
    }

    /** Whether a template of that path is held to the budget: one the sandbox is on for. */
    public static function budgeted(string $path): bool
    {
        return UntrustedTemplateLoader::isUntrusted($path) || PackageTemplateLoader::extensionOf($path) !== null;
    }
}
