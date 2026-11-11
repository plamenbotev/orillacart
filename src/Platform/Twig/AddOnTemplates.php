<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Extension\Storefront\TemplateFailed;
use OrillaCart\Extension\Storefront\Templates;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\View\TemplateRenderer;
use Twig\Environment;
use Twig\Error\Error;

/** The storefront's templates for add-ons (extension API 24): rendered as the shop renders its own. */
final readonly class AddOnTemplates implements Templates
{
    /** The folders of the shop's own templates a theme or an untrusted folder may override. */
    private const array OVERRIDABLE = ['storefront', 'emails'];

    public function __construct(private TemplateRenderer $renderer, private Environment $twig, private PluginContext $context) {}

    public function render(string $name, array $context = []): string
    {
        if (!$this->exists($name)) {
            throw new TemplateFailed(\sprintf('There is no template "%s".', $name));
        }
        try {
            return $this->renderer->render($name, $context);
        } catch (Error $e) {
            throw new TemplateFailed($e->getRawMessage() . ' (' . $name . ', line ' . $e->getTemplateLine() . ')', 0, $e);
        }
    }

    public function exists(string $name): bool
    {
        return $this->twig->getLoader()->exists($name);
    }

    public function names(): array
    {
        $root = $this->context->path(TwigFactory::PLUGIN_FOLDER);
        $names = [];
        foreach (self::OVERRIDABLE as $folder) {
            if (!is_dir($root . '/' . $folder)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                if ($item instanceof \SplFileInfo && $item->isFile() && str_ends_with($item->getFilename(), '.twig')) {
                    $names[] = substr($item->getPathname(), \strlen($root) + 1);
                }
            }
        }
        sort($names);

        return $names;
    }

    public function original(string $name): ?string
    {
        if (!\in_array($name, $this->names(), true)) {
            return null;
        }
        $source = file_get_contents($this->context->path(TwigFactory::PLUGIN_FOLDER) . '/' . $name);

        return \is_string($source) ? $source : null;
    }
}
