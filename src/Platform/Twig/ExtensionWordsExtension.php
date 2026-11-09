<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Packages\Application\ExtensionTranslations;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The extensions' words in their templates (design 57): what ExtensionWordsVisitor turns an extension's `__`,
 * `_x`, `_n` and `_nx` into, with the extension's id first.
 */
final class ExtensionWordsExtension extends AbstractExtension
{
    public function __construct(private readonly ExtensionTranslations $translations) {}

    public function getFunctions(): array
    {
        $prefix = ExtensionWordsVisitor::PREFIX;

        return [
            new TwigFunction($prefix . '__', fn(string $extension, string $text): string => $this->translations->word($extension, $text)),
            new TwigFunction($prefix . '_x', fn(string $extension, string $text, string $context): string => $this->translations->word($extension, $text, $context)),
            new TwigFunction($prefix . '_n', fn(string $extension, string $single, string $plural, int|float|string $count): string => $this->translations->plural($extension, $single, $plural, (int) $count)),
            new TwigFunction($prefix . '_nx', fn(string $extension, string $single, string $plural, int|float|string $count, string $context): string => $this->translations->plural($extension, $single, $plural, (int) $count, $context)),
        ];
    }

    public function getNodeVisitors(): array
    {
        return [new ExtensionWordsVisitor()];
    }
}
