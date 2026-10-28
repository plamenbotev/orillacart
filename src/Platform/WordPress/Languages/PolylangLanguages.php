<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Languages;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\I18n\Language;

/**
 * Polylang's languages, through its documented functions (pll_*). Its translations are equals: none of them is
 * the original, so the shop decides which one is the product (Catalog's ProductTranslations).
 */
final class PolylangLanguages implements ContentLanguages
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
            $codes = self::strings(pll_languages_list(['fields' => 'slug']));
            $locales = self::strings(pll_languages_list(['fields' => 'locale']));
            $names = self::strings(pll_languages_list(['fields' => 'name']));
            $this->languages = [];
            foreach ($codes as $i => $code) {
                $this->languages[] = new Language($code, $locales[$i] ?? $code, $names[$i] ?? $code);
            }
        }

        return $this->languages;
    }

    public function default(): ?Language
    {
        return $this->byCode(pll_default_language('slug'));
    }

    public function current(): ?Language
    {
        return $this->byCode(pll_current_language('slug'));
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
        $code = pll_get_post_language($postId, 'slug');

        return \is_string($code) && $code !== '' ? $code : null;
    }

    public function postTranslations(int $postId): array
    {
        return self::ids(pll_get_post_translations($postId));
    }

    public function termTranslations(int $termId, string $taxonomy): array
    {
        return self::ids(pll_get_term_translations($termId));
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

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_map(strval(...), array_filter($values, is_scalar(...)))) : [];
    }

    /**
     * @return array<string, int>
     */
    private static function ids(mixed $translations): array
    {
        $ids = [];
        foreach (\is_array($translations) ? $translations : [] as $code => $id) {
            if (\is_string($code) && is_numeric($id) && (int) $id > 0) {
                $ids[$code] = (int) $id;
            }
        }

        return $ids;
    }
}
