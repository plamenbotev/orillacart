<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Languages;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\I18n\Language;

/**
 * WPML's languages, through its documented filters (wpml_active_languages, wpml_default_language,
 * wpml_current_language, wpml_element_language_code, wpml_object_id).
 */
final class WpmlLanguages implements ContentLanguages
{
    /** @var list<Language>|null */
    private ?array $languages = null;

    public function multilingual(): bool
    {
        return true;
    }

    public function languages(): array
    {
        if ($this->languages === null) {
            $active = apply_filters('wpml_active_languages', null, ['skip_missing' => 0, 'orderby' => 'custom']);
            $this->languages = [];
            foreach (\is_array($active) ? $active : [] as $code => $language) {
                if (!\is_string($code) || !\is_array($language)) {
                    continue;
                }
                $locale = $language['default_locale'] ?? null;
                $name = $language['native_name'] ?? ($language['translated_name'] ?? null);
                $this->languages[] = new Language($code, \is_string($locale) && $locale !== '' ? $locale : $code, \is_string($name) && $name !== '' ? $name : $code);
            }
        }

        return $this->languages;
    }

    public function default(): ?Language
    {
        return $this->byCode(apply_filters('wpml_default_language', null));
    }

    public function current(): ?Language
    {
        // "all" on the admin screens that list every language: no one language is shown.
        return $this->byCode(apply_filters('wpml_current_language', null));
    }

    public function forLocale(string $locale): ?Language
    {
        foreach ($this->languages() as $language) {
            if ($language->locale === $locale) {
                return $language;
            }
        }

        return null;
    }

    public function ofPost(int $postId): ?string
    {
        $type = get_post_type($postId);
        if ($type === false) {
            return null;
        }
        $code = apply_filters('wpml_element_language_code', null, ['element_id' => $postId, 'element_type' => $type]);

        return \is_string($code) && $code !== '' ? $code : null;
    }

    public function postTranslations(int $postId): array
    {
        $type = get_post_type($postId);

        return $type === false || $this->ofPost($postId) === null ? [] : $this->translations($postId, $type);
    }

    public function homeUrl(Language $language): string
    {
        $url = apply_filters('wpml_permalink', home_url('/'), $language->code);

        return \is_string($url) && $url !== '' ? $url : home_url('/');
    }

    public function termTranslations(int $termId, string $taxonomy): array
    {
        return $this->translations($termId, $taxonomy);
    }

    /**
     * Each language's translation of the post or term, by its id: wpml_object_id answers one language at a time.
     *
     * @return array<string, int>
     */
    private function translations(int $id, string $type): array
    {
        $ids = [];
        foreach ($this->languages() as $language) {
            $translation = apply_filters('wpml_object_id', $id, $type, false, $language->code);
            if (is_numeric($translation) && (int) $translation > 0) {
                $ids[$language->code] = (int) $translation;
            }
        }

        return $ids;
    }

    private function byCode(mixed $code): ?Language
    {
        foreach ($this->languages() as $language) {
            if ($language->code === $code) {
                return $language;
            }
        }

        return null;
    }
}
