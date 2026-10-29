<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Translations\Application\Dto\ContentLanguageView;
use OrillaCart\Translations\Domain\TranslationRepository;
use OrillaCart\Translations\Domain\TranslationRuleViolation;

/**
 * The shop's own words in the site's other languages (design 51): an attribute's name, an option's, a shipping
 * method's title. Their own fields hold the default language; this holds the others, by WordPress locale, for the
 * languages Polylang or WPML gives the site. Without either there is nothing to translate into.
 */
final readonly class ContentTranslations
{
    /** What an item's key in a field may be: lowercase letters, digits, dashes and underscores. */
    private const string KEY = '[a-z0-9][a-z0-9_-]{0,39}';

    public function __construct(
        private TranslationRepository $translations,
        private TranslatableTypes $types,
        private ContentLanguages $languages,
        private Languages $locales,
    ) {}

    /**
     * The languages to translate into, the default first.
     *
     * @return list<ContentLanguageView>
     */
    public function languages(): array
    {
        $default = $this->languages->default();
        $views = array_map(static fn($language): ContentLanguageView => new ContentLanguageView($language->code, $language->locale, $language->name, $language->code === $default?->code), $this->languages->languages());
        usort($views, static fn(ContentLanguageView $a, ContentLanguageView $b): int => $b->default <=> $a->default);

        return $views;
    }

    /**
     * The field of each object in the language shown, the request's or the locale's (an email in a customer's);
     * those without a translation, or a site in one language, are left out: their own value stands.
     *
     * @param list<int> $ids
     *
     * @return array<int, string> by object id
     */
    public function translated(string $type, array $ids, string $field = 'name', ?string $locale = null): array
    {
        $locale = $this->shownLocale($locale);

        return $locale === null || $ids === [] ? [] : $this->translations->values($type, array_values(array_unique($ids)), $field, $locale);
    }

    /**
     * Every field of each object in the language shown, as translated(); for objects with several fields.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, string>> by object id and field
     */
    public function shown(string $type, array $ids, ?string $locale = null): array
    {
        $locale = $this->shownLocale($locale);

        return $locale === null || $ids === [] ? [] : $this->translations->inLocale($type, array_values(array_unique($ids)), $locale);
    }

    /**
     * The locale words are shown in, the request's or the one given, when it is not the default language's, which
     * the fields hold; null when nothing is translated for it.
     */
    public function shownLocale(?string $locale = null): ?string
    {
        if (!$this->languages->multilingual()) {
            return null;
        }
        $locale ??= $this->locales->current();

        return $locale === $this->languages->default()?->locale ? null : $locale;
    }

    /**
     * Every translation of the objects, for the admin screens.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, array<string, string>>> by object id, field and locale
     */
    public function of(string $type, array $ids): array
    {
        $this->type($type);

        return $this->translations->all($type, $ids);
    }

    /**
     * Checks an object's translations before its owner saves it, with them: the fields the type translates, the
     * site's other languages, the fields' lengths. A field given replaces all its languages; an empty value
     * removes one.
     *
     * @param array<string, array<string, string>> $fields by field and locale
     *
     * @return array<string, array<string, string>> trimmed, for write()
     *
     * @throws TranslationRuleViolation for a type, field, language or length the shop does not translate
     */
    public function check(string $type, array $fields): array
    {
        $translatable = $this->type($type);
        $locales = array_map(static fn(ContentLanguageView $language): string => $language->locale, array_filter($this->languages(), static fn(ContentLanguageView $language): bool => !$language->default));
        $checked = [];
        foreach ($fields as $field => $values) {
            $max = self::longest($translatable, $field) ?? throw new TranslationRuleViolation('A %1$s has no field "%2$s" to translate.', 'orillacart_translation_field', values: [$type, $field]);
            foreach ($values as $locale => $value) {
                if (!\in_array($locale, $locales, true)) {
                    throw new TranslationRuleViolation('The site does not translate into %s.', 'orillacart_translation_language', values: [$locale]);
                }
                $value = trim($value);
                if (mb_strlen($value) > $max) {
                    throw new TranslationRuleViolation('A translation of %1$s is up to %2$d characters.', 'orillacart_translation_length', values: [$field, $max]);
                }
                $checked[$field][$locale] = $value;
            }
        }

        return $checked;
    }

    /**
     * Writes what check() returned for the object its owner just saved.
     *
     * @param array<string, array<string, string>> $fields by field and locale
     */
    public function write(string $type, int $id, array $fields): void
    {
        $this->translations->replace($type, $id, $fields);
    }

    /**
     * The objects are gone, and their translations with them.
     *
     * @param list<int> $ids
     */
    public function forget(string $type, array $ids): void
    {
        $this->translations->forget($type, $ids);
    }

    /**
     * What the translation service last gave for an object's fields, by field and locale.
     *
     * @return array<string, array<string, string>>
     */
    public function serviceCopies(string $type, int $id): array
    {
        return $this->translations->serviceCopies($type, [$id])[$id] ?? [];
    }

    /**
     * @param array<string, array<string, string>> $fields what the service gave now, by field and locale
     */
    public function keepServiceCopies(string $type, int $id, array $fields): void
    {
        $this->translations->keepServiceCopies($type, $id, $fields);
    }

    /** The field's longest value, also for a field of a listed item ("pickup.{key}.name"); null for none. */
    private static function longest(TranslatableType $type, string $field): ?int
    {
        foreach ($type->fields() as $name => $max) {
            if ($name === $field || (str_contains($name, '{key}') && preg_match('/^' . str_replace('\\{key\\}', self::KEY, preg_quote($name, '/')) . '$/', $field) === 1)) {
                return $max;
            }
        }

        return null;
    }

    private function type(string $type): TranslatableType
    {
        return $this->types->get($type) ?? throw new TranslationRuleViolation('The shop translates no "%s".', 'orillacart_translation_type', values: [$type]);
    }
}
