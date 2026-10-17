<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Shared\View\TemplateRenderer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Twig\Environment;

/**
 * Renders templates with view DTOs turned into the same arrays the REST API sends, so templates name fields
 * as the API does: {{ order.shipping_total }}.
 */
final readonly class TwigRenderer implements TemplateRenderer
{
    public function __construct(
        private Environment $twig,
        private ?NormalizerInterface $normalizer = null,
    ) {}

    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $this->normalized($context));
    }

    public function renderBlock(string $template, string $block, array $context = []): string
    {
        return $this->twig->load($template)->renderBlock($block, $this->normalized($context));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normalized(array $context): array
    {
        if ($this->normalizer === null) {
            return $context;
        }

        return array_map(fn(mixed $value): mixed => \is_object($value) ? $this->normalizer->normalize($value, 'json') : $value, $context);
    }
}
