<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Scripting;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Twig\EmailTwig;
use OrillaCart\Platform\Twig\StorefrontSandbox;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Scripting\Application\ScriptGuide;

/**
 * The guide as the plugin ships it: docs/extension-scripts-guide.md in the plugin's folder (bin/build copies it).
 */
final readonly class PluginScriptGuide implements ScriptGuide
{
    public const string FILE = 'docs/extension-scripts-guide.md';
    public const string REST_FILE = 'docs/rest-api.md';

    public function __construct(private PluginContext $context) {}

    public function markdown(): string
    {
        return $this->read(self::FILE);
    }

    public function restApi(): string
    {
        return $this->read(self::REST_FILE);
    }

    public function templateNotes(): array
    {
        $notes = [];
        $folder = $this->context->path(TwigFactory::PLUGIN_FOLDER . '/storefront');
        $files = glob($folder . '/*.twig');
        foreach ($files === false ? [] : $files as $file) {
            $text = file_get_contents($file);
            if ($text === false || preg_match('/^\{#(.*?)#\}/s', $text, $match) !== 1) {
                continue;
            }
            $note = trim((string) preg_replace('/^\s*#\s?/m', '', $match[1]));
            $notes['storefront/' . basename($file)] = preg_replace('/\s*\n\s*/', ' ', $note) ?? $note;
        }

        return $notes;
    }

    public function templateSandbox(): array
    {
        return ['tags' => EmailTwig::TAGS, 'filters' => EmailTwig::FILTERS, 'functions' => StorefrontSandbox::FUNCTIONS, 'tests' => EmailTwig::TESTS];
    }

    private function read(string $file): string
    {
        $path = $this->context->path($file);
        $text = is_readable($path) ? file_get_contents($path) : false;

        return $text === false ? '' : $text;
    }
}
