<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Shared\View\TemplateRenderer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * Renders templates with view DTOs turned into the same arrays the REST API sends, so templates name fields
 * as the API does: {{ order.shipping_total }}. Twig is strict about variables: with WP_DEBUG a template naming
 * one it was not given fails; on a live site that is logged, and the template rendered again as if it were empty,
 * unless Strictness says otherwise.
 */
final readonly class TwigRenderer implements TemplateRenderer
{
    public function __construct(
        private Environment $twig,
        private ?NormalizerInterface $normalizer = null,
        private LoggerInterface $logger = new NullLogger(),
        private ?Strictness $strictness = null,
    ) {}

    public function render(string $template, array $context = []): string
    {
        $context = $this->normalized($context);

        return $this->leniently($template, fn(): string => $this->twig->render($template, $context));
    }

    public function renderBlock(string $template, string $block, array $context = []): string
    {
        $context = $this->normalized($context);

        return $this->leniently($template, fn(): string => $this->twig->load($template)->renderBlock($block, $context));
    }

    /**
     * @param \Closure(): string $render
     */
    private function leniently(string $template, \Closure $render): string
    {
        if (!$this->twig->isStrictVariables() || $this->twig->isDebug() || $this->strictness?->on() === true) {
            return $render();
        }
        try {
            return $render();
        } catch (RuntimeError $e) {
            // Only what strictness found: a template that fails leniently too fails as it is.
            $this->twig->disableStrictVariables();
            try {
                $html = $render();
            } finally {
                $this->twig->enableStrictVariables();
            }
            $this->logger->warning('Template {template} names what it was not given: {message}', ['template' => $template, 'message' => $e->getMessage()]);

            return $html;
        }
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

        // Arrays too: they may hold view DTOs, such as a list of products with their links.
        return array_map(fn(mixed $value): mixed => \is_object($value) || \is_array($value) ? $this->normalizer->normalize($value, 'json') : $value, $context);
    }
}
