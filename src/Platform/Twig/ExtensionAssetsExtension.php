<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Packages\Application\ExtensionAssets;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * An extension's scripts and stylesheets in its templates (design 59): what ExtensionWordsVisitor turns an
 * extension's `asset('portal.js')` into, with the extension's id first.
 */
final class ExtensionAssetsExtension extends AbstractExtension
{
    public function __construct(private readonly ExtensionAssets $assets) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction(ExtensionWordsVisitor::PREFIX . ExtensionWordsVisitor::ASSET, $this->tag(...), ['is_safe' => ['html']]),
            // The name as a template writes it, so one parses outside an extension (the static check reads them
            // unmarked); only an extension's template, where the visitor binds it, can call it.
            new TwigFunction(ExtensionWordsVisitor::ASSET, static fn(string $name): string => throw new \RuntimeException('asset() is for an extension\'s own templates.'), ['is_safe' => ['html']]),
        ];
    }

    /** The file's tag; a script's address is one ScriptFreeHtml lets run, the one kind of script that may (design 73). */
    private function tag(string $extension, string $name): string
    {
        $tag = $this->assets->tag($extension, $name);
        if ($tag !== '' && str_ends_with($name, '.js')) {
            $url = $this->assets->url($extension, $name);
            if ($url !== null) {
                ScriptFreeHtml::allow($url);
            }
        }

        return $tag;
    }
}
