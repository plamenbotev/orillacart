<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Languages;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\I18n\Language;

/**
 * The site's content languages from the plugin that translates them: Polylang, WPML, or none. Chosen on first use,
 * when the plugins are loaded, not when the container is compiled on the build machine.
 */
final class WordPressContentLanguages implements ContentLanguages
{
    private ?ContentLanguages $plugin = null;

    public function multilingual(): bool
    {
        return $this->plugin()->multilingual();
    }

    public function languages(): array
    {
        return $this->plugin()->languages();
    }

    public function default(): ?Language
    {
        return $this->plugin()->default();
    }

    public function current(): ?Language
    {
        return $this->plugin()->current();
    }

    public function forLocale(string $locale): ?Language
    {
        return $this->plugin()->forLocale($locale);
    }

    public function ofPost(int $postId): ?string
    {
        return $this->plugin()->ofPost($postId);
    }

    public function postTranslations(int $postId): array
    {
        return $this->plugin()->postTranslations($postId);
    }

    public function termTranslations(int $termId, string $taxonomy): array
    {
        return $this->plugin()->termTranslations($termId, $taxonomy);
    }

    private function plugin(): ContentLanguages
    {
        if ($this->plugin === null && did_action('plugins_loaded') === 0) {
            // Asked before the plugins are loaded: not yet known, and not remembered.
            return new OneLanguage();
        }

        // Polylang first: it answers some of WPML's filters too, for themes written for WPML.
        return $this->plugin ??= match (true) {
            \function_exists('pll_get_post_translations') && \function_exists('pll_languages_list') => new PolylangLanguages(),
            \defined('ICL_SITEPRESS_VERSION') => new WpmlLanguages(),
            default => new OneLanguage(),
        };
    }
}
