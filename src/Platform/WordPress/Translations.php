<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Platform\PluginContext;

/**
 * Loads the translations shipped in the plugin's languages folder: orillacart-<locale>.mo for the PHP code and
 * the templates, orillacart-<locale>-<md5>.json for each script. languages/orillacart.pot lists the strings
 * (npm run i18n:pot).
 */
final readonly class Translations implements Registrar
{
    public const string DOMAIN = 'orillacart';
    public const string FOLDER = 'languages';

    public function __construct(private PluginContext $context) {}

    public function register(): void
    {
        add_action('init', $this->load(...), 0);
    }

    public function load(): void
    {
        load_plugin_textdomain(self::DOMAIN, false, \dirname(plugin_basename($this->context->pluginFile)) . '/' . self::FOLDER);
    }
}
