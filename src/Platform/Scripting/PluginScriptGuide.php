<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Scripting;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Scripting\Application\ScriptGuide;

/**
 * The guide as the plugin ships it: docs/extension-scripts-guide.md in the plugin's folder (bin/build copies it).
 */
final readonly class PluginScriptGuide implements ScriptGuide
{
    public const string FILE = 'docs/extension-scripts-guide.md';

    public function __construct(private PluginContext $context) {}

    public function markdown(): string
    {
        $path = $this->context->path(self::FILE);
        $text = is_readable($path) ? file_get_contents($path) : false;

        return $text === false ? '' : $text;
    }
}
