<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\View\TemplateContextSource;

/**
 * `template_data "storefront/product.html.twig" fn(context)`: what the extensions add to a template's context,
 * under `ext.<extension>`. A handler that fails adds nothing; the failure counts against the extension.
 */
final readonly class ScriptedTemplateData implements TemplateContextSource
{
    public function __construct(private PackageService $packages, private ScriptRunner $runner) {}

    public function add(string $template, array $context): array
    {
        /** @var array<string, array<mixed>> $added */
        $added = [];
        foreach ($this->packages->loaded() as $loaded) {
            foreach ($loaded->manifest->ofKind('template_data') as $declaration) {
                if ($declaration['name'] !== $template || $declaration['handler'] === null) {
                    continue;
                }
                try {
                    $data = $this->runner->run($loaded, $declaration['handler'], [Ops::plain($context)]);
                } catch (HandlerFailed) {
                    continue;
                }
                if (\is_array($data)) {
                    $plain = Ops::plain($data);
                    $added[$loaded->package->slug()] = (\is_array($plain) ? $plain : []) + ($added[$loaded->package->slug()] ?? []);
                }
            }
        }

        return $added;
    }
}
