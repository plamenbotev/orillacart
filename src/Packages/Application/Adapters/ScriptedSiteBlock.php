<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Storefront\SiteBlock;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Application\HtmlCleaner;
use OrillaCart\Packages\Application\ScriptRunner;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/**
 * `site_block "id" { title, description, settings: { heading: text { label: "Heading" } }; fn render(settings) }`: a
 * block for anywhere on the site, with settings the owner fills as an extension's own are declared; what the script
 * renders is cleaned as a post's HTML is, so a script cannot put a script tag on the storefront.
 */
final class ScriptedSiteBlock extends ScriptedProvider implements SiteBlock
{
    public function __construct(LoadedPackage $loaded, array $declaration, ScriptRunner $runner, private readonly HtmlCleaner $cleaner)
    {
        parent::__construct($loaded, $declaration, $runner);
    }

    public function title(): string
    {
        $title = $this->declaration['props']['title'] ?? null;

        return \is_string($title) ? $this->loaded->word($title) : parent::title();
    }

    public function description(): string
    {
        $description = $this->declaration['props']['description'] ?? null;

        return \is_string($description) ? $this->loaded->word($description) : '';
    }

    public function settings(): array
    {
        $settings = $this->declaration['props']['settings'] ?? null;

        // Labels and help in the language the shop reads, as the extension's other words.
        return array_map(fn(Setting $setting): Setting => new Setting($setting->key, $this->loaded->word($setting->label), $setting->type, $setting->required, $setting->help === '' ? '' : $this->loaded->word($setting->help), array_map($this->loaded->word(...), $setting->choices)), \is_array($settings) ? PackageManifest::settingsOf($settings) : []);
    }

    public function render(array $settings): string
    {
        try {
            $html = $this->call('render', [$settings]);
        } catch (HandlerFailed) {
            return '';
        }

        return $this->cleaner->clean(Values::text($html));
    }
}
