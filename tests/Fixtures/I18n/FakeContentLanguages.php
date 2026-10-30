<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\I18n;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\FakeLanguages;

/**
 * Polylang or WPML in memory: languages, and groups of posts that translate each other.
 */
final class FakeContentLanguages implements ContentLanguages
{
    /** @var list<array<string, int>> */
    private array $groups = [];
    public ?string $current = null;
    /** Without a current language, the one of this locale, as WordPress's follows the locale the shop works in. */
    public ?FakeLanguages $follows = null;

    /**
     * @param list<Language> $languages the first is the default
     */
    public function __construct(public array $languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')]) {}

    /**
     * @param array<string, int> $posts post id by language code
     */
    public function translate(array $posts): void
    {
        $this->groups[] = $posts;
    }

    public function multilingual(): bool
    {
        return $this->languages !== [];
    }

    public function languages(): array
    {
        return $this->languages;
    }

    public function default(): ?Language
    {
        return $this->languages[0] ?? null;
    }

    public function current(): ?Language
    {
        // Without one set, the language of the locale the shop works in: FakeLanguages's, or WordPress's own.
        $locale = $this->follows !== null ? $this->follows->locale : (\function_exists('determine_locale') ? determine_locale() : null);
        foreach ($this->languages as $language) {
            if ($this->current === null ? $language->locale === $locale : $language->code === $this->current) {
                return $language;
            }
        }

        return null;
    }

    public function setPostLanguage(int $postId, Language $language): void
    {
        if ($this->postTranslations($postId) === []) {
            $this->groups[] = [$language->code => $postId];
        }
    }

    public function addPostTranslation(int $postId, int $translationId, Language $language): void
    {
        foreach ($this->groups as $i => $group) {
            if (\in_array($postId, $group, true)) {
                $this->groups[$i][$language->code] = $translationId;
            }
        }
    }

    public function homeUrl(Language $language): string
    {
        return "https://shop.test/{$language->code}/";
    }

    public function forLocale(string $locale): ?Language
    {
        foreach ($this->languages as $language) {
            if ($language->locale === $locale) {
                return $language;
            }
        }

        return null;
    }

    public function ofPost(int $postId): ?string
    {
        $code = array_search($postId, $this->postTranslations($postId), true);

        return \is_string($code) ? $code : null;
    }

    public function postTranslations(int $postId): array
    {
        foreach ($this->groups as $group) {
            if (\in_array($postId, $group, true)) {
                return $group;
            }
        }

        return [];
    }

    public function termTranslations(int $termId, string $taxonomy): array
    {
        return [];
    }
}
