<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\ExtensionWord;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageNotFound;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Shared\I18n\PluralForms;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Application\Dto\ContentLanguageView;
use OrillaCart\Translations\Application\TranslationPackages;

/**
 * An extension's words and their translations, for the Extensions screen, the assistant and an export (design
 * 57): each word with where it stands and its forms in each of the site's other languages; a save of some of
 * them, the others kept; and the translation service told.
 *
 * @phpstan-type Given array<string, array<string, list<string>|string>>
 * @phpstan-type Shown array{languages: list<array{locale: string, name: string, forms: int}>, words: list<array{key: string, text: string, plural: ?string, context: string, where: list<string>, translations: array<string, list<string>>}>}
 */
final readonly class PackageTranslations
{
    /** The most forms a language has (Arabic's): a plural's fields go up to it. */
    private const int MOST_FORMS = 6;

    public function __construct(
        private PackageRepository $packages,
        private ExtensionWords $words,
        private ContentTranslations $translations,
        private ExtensionTranslations $lookup,
        private ?TranslationPackages $service = null,
    ) {}

    /** @return Shown */
    public function show(string $slug): array
    {
        $package = $this->get($slug);
        $stored = $this->stored($package);
        $languages = $this->languages();

        return [
            'languages' => $languages,
            'words' => array_map(static fn(ExtensionWord $word): array => [
                'key' => $word->key(),
                'text' => $word->text,
                'plural' => $word->plural,
                'context' => $word->context,
                'where' => $word->where,
                'translations' => self::forms($word, $stored, $languages),
            ], $this->words->of($package)),
        ];
    }

    /**
     * Saves the translations given, by word and language: a word's forms as a list (one for words without a
     * plural), or a string for its only form. A language left out keeps what it had; an empty form removes it.
     *
     * @param Given $given
     *
     * @return Shown
     *
     * @throws PackageRuleViolation for a word the extension does not have, or more forms than the language has
     */
    public function save(string $slug, array $given): array
    {
        $package = $this->get($slug);
        $this->write($package, $given, true);

        return $this->show($slug);
    }

    /**
     * What an export carries: the forms translated, by word and language.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function export(Package $package): array
    {
        $stored = $this->stored($package);
        $languages = $this->languages();
        $export = [];
        foreach ($this->words->of($package) as $word) {
            foreach (self::forms($word, $stored, $languages) as $locale => $forms) {
                if (array_filter($forms, static fn(string $form): bool => $form !== '') !== []) {
                    $export[$word->key()][$locale] = $forms;
                }
            }
        }

        return $export;
    }

    /**
     * An import's translations: those of the words the extension has, in the site's languages; the rest are left.
     *
     * @param Given $given
     */
    public function import(Package $package, array $given): void
    {
        if ($given !== []) {
            $this->write($package, $given, false);
        }
    }

    public function forget(Package $package): void
    {
        $this->translations->forget(ExtensionWordTypes::TYPE, [$package->id()]);
        $this->lookup->changed($package->slug());
        $this->service?->removed(ExtensionPackages::KIND, $package->id());
    }

    /** The extension's files changed: the translation service gets its words as they are now. */
    public function changed(Package $package): void
    {
        $this->service?->changed(ExtensionPackages::KIND, $package->id());
    }

    /**
     * @param Given $given
     */
    private function write(Package $package, array $given, bool $strict): void
    {
        $words = [];
        foreach ($this->words->of($package) as $word) {
            $words[$word->key()] = $word;
        }
        $counts = [];
        foreach ($this->languages() as $language) {
            $counts[$language['locale']] = $language['forms'];
        }
        $stored = $this->stored($package);
        $fields = [];
        foreach ($given as $key => $byLocale) {
            $word = $words[$key] ?? null;
            if ($word === null) {
                if ($strict) {
                    throw new PackageRuleViolation('The extension has no word "%s" to translate.', 'orillacart_extension_word', values: [$key]);
                }
                continue;
            }
            $fieldCount = $word->plural === null ? 1 : self::MOST_FORMS;
            for ($form = 0; $form < $fieldCount; ++$form) {
                $fields[ExtensionWord::field($key, $form)] = $stored[ExtensionWord::field($key, $form)] ?? [];
            }
            foreach (\is_array($byLocale) ? $byLocale : [] as $locale => $forms) {
                $forms = \is_string($forms) ? [$forms] : array_values(array_map(strval(...), \is_array($forms) ? $forms : []));
                $most = $word->plural === null ? 1 : ($counts[$locale] ?? PluralForms::count((string) $locale));
                if (\count($forms) > $most) {
                    if ($strict) {
                        throw new PackageRuleViolation('"%1$s" has %2$d forms in %3$s, not %4$d.', 'orillacart_extension_word_forms', values: [$word->text, $most, $locale, \count($forms)]);
                    }
                    $forms = \array_slice($forms, 0, $most);
                }
                for ($form = 0; $form < $fieldCount; ++$form) {
                    $fields[ExtensionWord::field($key, $form)][(string) $locale] = $forms[$form] ?? '';
                }
            }
        }
        if ($fields === []) {
            return;
        }
        $this->translations->write(ExtensionWordTypes::TYPE, $package->id(), $this->translations->check(ExtensionWordTypes::TYPE, $fields));
        $this->lookup->changed($package->slug());
        $this->changed($package);
    }

    /**
     * A word's forms in each language: as many as the language has for a plural, one otherwise.
     *
     * @param array<string, array<string, string>>                     $stored    by field and locale
     * @param list<array{locale: string, name: string, forms: int}> $languages
     *
     * @return array<string, list<string>>
     */
    private static function forms(ExtensionWord $word, array $stored, array $languages): array
    {
        $forms = [];
        foreach ($languages as $language) {
            $count = $word->plural === null ? 1 : $language['forms'];
            for ($form = 0; $form < $count; ++$form) {
                $forms[$language['locale']][] = $stored[ExtensionWord::field($word->key(), $form)][$language['locale']] ?? '';
            }
        }

        return $forms;
    }

    /** @return array<string, array<string, string>> by field and locale */
    private function stored(Package $package): array
    {
        return $this->translations->of(ExtensionWordTypes::TYPE, [$package->id()])[$package->id()] ?? [];
    }

    /**
     * The languages to translate into, with how many plural forms each has.
     *
     * @return list<array{locale: string, name: string, forms: int}>
     */
    private function languages(): array
    {
        return array_values(array_map(
            static fn(ContentLanguageView $language): array => ['locale' => $language->locale, 'name' => $language->name, 'forms' => PluralForms::count($language->locale)],
            array_filter($this->translations->languages(), static fn(ContentLanguageView $language): bool => !$language->default),
        ));
    }

    private function get(string $slug): Package
    {
        return $this->packages->find($slug) ?? throw new PackageNotFound($slug);
    }
}
