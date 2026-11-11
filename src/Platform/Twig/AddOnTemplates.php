<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Extension\Storefront\TemplateFailed;
use OrillaCart\Extension\Storefront\Templates;
use OrillaCart\Shared\View\TemplateRenderer;
use Twig\Environment;
use Twig\Error\Error;

/** The storefront's templates for add-ons (extension API 24): rendered as the shop renders its own. */
final readonly class AddOnTemplates implements Templates
{
    public function __construct(private TemplateRenderer $renderer, private Environment $twig) {}

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
}
