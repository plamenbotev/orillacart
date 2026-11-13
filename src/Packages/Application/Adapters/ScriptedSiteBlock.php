<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Storefront\BlockEditor;
use OrillaCart\Extension\Storefront\BlockPlace;
use OrillaCart\Extension\Storefront\PlacedSiteBlock;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Application\ExtensionAssets;
use OrillaCart\Packages\Application\ExtensionFrames;
use OrillaCart\Packages\Application\HtmlCleaner;
use OrillaCart\Packages\Application\ScriptRunner;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/**
 * `site_block "id" { title, description, settings: { heading: text { label: "Heading" } }, assets: ["tabs.js"],
 * inner: true, editor: "tabs-editor.js"; fn render(settings, context) }`: a block for anywhere on the site, with
 * settings the owner fills as an extension's own are declared, and a block's own kinds (design 73: image, link,
 * color, product, category, rich_text, list). What the script renders is cleaned as a post's HTML is, so a script
 * cannot put a script tag on the storefront; its `assets` are printed after it, where the block is placed. With
 * `inner: true` the owner places blocks inside it: `context.content` marks where they go (after the block's own
 * HTML when it is left out). `editor` is a script of its own that edits the settings, in a sandboxed frame.
 */
final class ScriptedSiteBlock extends ScriptedProvider implements PlacedSiteBlock
{
    public function __construct(
        LoadedPackage $loaded,
        array $declaration,
        ScriptRunner $runner,
        private readonly HtmlCleaner $cleaner,
        private readonly ?ExtensionAssets $assets = null,
        private readonly ?ExtensionFrames $frames = null,
    ) {
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
        return array_map($this->translated(...), \is_array($settings) ? PackageManifest::settingsOf($settings) : []);
    }

    public function inner(): bool
    {
        return ($this->declaration['props']['inner'] ?? false) === true;
    }

    public function render(array $settings): string
    {
        return $this->renderIn($settings, new BlockPlace());
    }

    public function renderIn(array $settings, BlockPlace $place): string
    {
        // Where the inner blocks go: a mark the script places, which no cleaning touches, replaced after.
        $mark = $this->inner() ? 'orillacart-content-' . bin2hex(random_bytes(8)) : '';
        try {
            $context = ['post_id' => $place->postId, 'preview' => $place->preview, 'content' => $mark];
            // A render written before it had a context (render(settings)) is called as written.
            $takes = ScriptRunner::takes($this->loaded, ScriptRunner::method($this->declaration, 'render'));
            $html = $this->call('render', $takes === 1 ? [$settings] : [$settings, $context]);
        } catch (HandlerFailed) {
            return '';
        }
        $html = $this->cleaner->clean(Values::text($html));
        if ($mark !== '') {
            $html = str_contains($html, $mark) ? str_replace($mark, $place->content, $html) : $html . $place->content;
        }

        return $html === '' ? '' : $html . Assets::tags($this->assets, $this->loaded, $this->declaration);
    }

    public function editor(): ?BlockEditor
    {
        $file = $this->declaration['props']['editor'] ?? null;
        if (!\is_string($file) || !str_ends_with($file, '.js') || $this->assets === null || $this->frames === null) {
            return null;
        }
        $url = $this->assets->url($this->loaded->package->slug(), $file);
        if ($url === null) {
            return null;
        }

        $slug = $this->loaded->package->slug();

        return new BlockEditor($this->frames->document($slug, \sprintf('<div id="editor"></div><script src="%s" defer></script>', htmlspecialchars($url, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'))), $slug);
    }

    private function translated(Setting $setting): Setting
    {
        return new Setting(
            $setting->key,
            $this->loaded->word($setting->label),
            $setting->type,
            $setting->required,
            $setting->help === '' ? '' : $this->loaded->word($setting->help),
            array_map($this->loaded->word(...), $setting->choices),
            $setting->goesWith,
            array_map($this->translated(...), $setting->fields),
        );
    }
}
