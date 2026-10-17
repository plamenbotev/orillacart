<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Shared\View\TemplateRenderer;
use Twig\Environment;

final readonly class TwigRenderer implements TemplateRenderer
{
    public function __construct(private Environment $twig) {}

    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
