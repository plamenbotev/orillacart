<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Setting;
use OrillaCart\Packages\Application\Dto\ExtensionWord;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Shared\I18n\PluralForms;
use OrillaCart\Shared\I18n\Translations;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Translations\Application\ContentTranslations;

/**
 * An extension's words in the language the shop shows (design 57): its own translation from the translations
 * table, else the plugin's for the same words (`__("Free shipping")`), else the words as written. What its
 * scripts' `__`, `_x`, `_n` and `_nx` call, its templates' too, and the adapters for what it declares. A
 * package's translations in a language are read once per request.
 */
final class ExtensionTranslations
{
    /** @var array<string, array<string, array<string, string>>> by extension, locale and field */
    private array $read = [];

    public function __construct(
        private readonly PackageRepository $packages,
        private readonly ContentTranslations $translations,
        private readonly Translations $shop,
        private readonly Languages $languages,
    ) {}

    public function word(string $extension, string $text, string $context = ''): string
    {
        if ($text === '') {
            return '';
        }
        $locale = $this->translations->shownLocale();
        if ($locale !== null) {
            $found = $this->fields($extension, $locale)[ExtensionWord::field(ExtensionWord::keyOf($text, null, $context))] ?? '';
            if ($found !== '') {
                return $found;
            }
        }

        return $this->shop->word($text, $context);
    }

    /** The form the count takes in the language shown; a form not translated takes the nearest filled one before it. */
    public function plural(string $extension, string $single, string $plural, int $count, string $context = ''): string
    {
        $locale = $this->translations->shownLocale();
        if ($locale !== null) {
            $fields = $this->fields($extension, $locale);
            $key = ExtensionWord::keyOf($single, $plural, $context);
            for ($form = PluralForms::index($locale, $count); $form >= 0; --$form) {
                $found = $fields[ExtensionWord::field($key, $form)] ?? '';
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return $this->shop->plural($single, $plural, $count, $context);
    }

    /**
     * The settings an extension declares, their labels, help and choices in the language shown.
     *
     * @return list<Setting>
     */
    public function settings(string $extension, Setting ...$settings): array
    {
        return array_map(fn(Setting $setting): Setting => new Setting(
            $setting->key,
            $this->word($extension, $setting->label),
            $setting->type,
            $setting->required,
            $this->word($extension, $setting->help),
            array_map(fn(string $label): string => $this->word($extension, $label), $setting->choices),
            $setting->goesWith,
        ), array_values($settings));
    }

    /** The locale words are shown in now: "de_DE". */
    public function locale(): string
    {
        return $this->languages->current();
    }

    /** Forgets what was read, after a save. */
    public function changed(string $extension): void
    {
        unset($this->read[$extension]);
    }

    /** @return array<string, string> by field */
    private function fields(string $extension, string $locale): array
    {
        if (!isset($this->read[$extension][$locale])) {
            $id = $this->packages->find($extension)?->id();
            $this->read[$extension][$locale] = $id === null ? [] : ($this->translations->shown(ExtensionWordTypes::TYPE, [$id], $locale)[$id] ?? []);
        }

        return $this->read[$extension][$locale];
    }
}
