<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\I18n;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\I18n\Language;

/**
 * Polylang or WPML in memory: languages, and groups of posts that translate each other.
 */
final class FakeContentLanguages implements ContentLanguages
{
    /** @var list<array<string, int>> */
    private array $groups = [];
    public ?string $current = null;

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
        foreach ($this->languages as $language) {
            if ($language->code === $this->current) {
                return $language;
            }
        }

        return null;
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
