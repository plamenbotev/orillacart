<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Infrastructure\WordPress;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\I18n\Translations;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationService;

/**
 * WPML's string packages (https://wpml.org/documentation/support/string-package-translation/), with WPML String
 * Translation: each object's words are a package of its kind, which WPML's translation management sends to
 * translators and services; what they finish is read back through wpml_translate_string, in each language.
 * A string is named by a hash of its field, so renaming its title or its words keeps it the same string.
 */
final readonly class WpmlStringPackages implements TranslationService
{
    public function __construct(
        private ContentLanguages $languages,
        private Translations $words,
    ) {}

    public function active(): bool
    {
        return \defined('WPML_ST_VERSION') && $this->languages->multilingual();
    }

    public function register(TranslationPackage $package, string $kindTitle): void
    {
        $wpml = self::package($package->kind, $package->id, $kindTitle, $package->title);
        do_action('wpml_start_string_package_registration', $wpml);
        foreach ($package->strings as $string) {
            do_action('wpml_register_string', $string->value, self::name($string), $wpml, $this->words->text($string->title), $string->multiline ? 'AREA' : 'LINE');
        }
        do_action('wpml_delete_unused_package_strings', $wpml);
    }

    public function remove(string $kind, int $id, string $kindTitle): void
    {
        do_action('wpml_delete_package', self::package($kind, $id, $kindTitle)['name'], $kindTitle);
    }

    public function translated(TranslationPackage $package, string $kindTitle): array
    {
        $wpml = self::package($package->kind, $package->id, $kindTitle, $package->title);
        $current = apply_filters('wpml_current_language', null);
        $default = $this->languages->default()?->code;
        $found = [];
        try {
            foreach ($this->languages->languages() as $language) {
                if ($language->code === $default) {
                    continue;
                }
                do_action('wpml_switch_language', $language->code);
                foreach ($package->strings as $string) {
                    $translation = apply_filters('wpml_translate_string', $string->value, self::name($string), $wpml);
                    // WPML answers the string itself where it has no translation.
                    if (\is_string($translation) && trim($translation) !== '' && $translation !== $string->value) {
                        $found[$string->key()][$language->locale] = $translation;
                    }
                }
            }
        } finally {
            do_action('wpml_switch_language', \is_string($current) ? $current : null);
        }

        return $found;
    }

    /**
     * @return array{kind: string, name: string, title: string}
     */
    private static function package(string $kind, int $id, string $kindTitle, string $title = ''): array
    {
        return ['kind' => $kindTitle, 'name' => $kind . '-' . $id, 'title' => $title];
    }

    private static function name(PackageString $string): string
    {
        return md5($string->key());
    }
}
