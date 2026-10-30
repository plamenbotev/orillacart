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

    /** The language a storefront request said it is in, while it is handled (RequestLanguage). */
    private ?Language $requested = null;

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

    /**
     * The request's, else the language of the locale the shop works in (Languages::in, as for an email or an order
     * edited in its customer's language), else the plugin's.
     */
    public function current(): ?Language
    {
        if ($this->requested !== null) {
            return $this->requested;
        }

        return ($this->multilingual() ? $this->forLocale(determine_locale()) : null) ?? $this->plugin()->current();
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function during(Language $language, \Closure $work): mixed
    {
        $before = $this->requested;
        $this->requested = $language;
        try {
            return $work();
        } finally {
            $this->requested = $before;
        }
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

    public function setPostLanguage(int $postId, Language $language): void
    {
        $this->plugin()->setPostLanguage($postId, $language);
    }

    public function addPostTranslation(int $postId, int $translationId, Language $language): void
    {
        $this->plugin()->addPostTranslation($postId, $translationId, $language);
    }

    public function homeUrl(Language $language): string
    {
        return $this->plugin()->homeUrl($language);
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
