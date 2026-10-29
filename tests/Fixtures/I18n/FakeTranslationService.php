<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\I18n;

use OrillaCart\Tests\Fixtures\FakeLanguages;
use OrillaCart\Tests\Fixtures\Translations\InMemoryTranslations;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackages;
use OrillaCart\Translations\Application\TranslationService;
use OrillaCart\Translations\Infrastructure\TaggedTranslationPackageSources;

/**
 * WPML's string packages in memory: the packages registered, and the translations its translators finished.
 */
final class FakeTranslationService implements TranslationService
{
    public bool $active = false;
    /** @var array<string, TranslationPackage> by kind and id, "carrier:3" */
    public array $packages = [];
    /** @var array<string, array<string, string>> what translators finished, by PackageString::key() and locale */
    public array $finished = [];

    /** Packages for a test that has no translation service. */
    public static function none(): TranslationPackages
    {
        $records = new InMemoryTranslations();

        return new TranslationPackages(new TaggedTranslationPackageSources([]), new self(), new ContentTranslations($records, $records, new FakeContentLanguages([]), new FakeLanguages()));
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function register(TranslationPackage $package, string $kindTitle): void
    {
        $this->packages["{$package->kind}:{$package->id}"] = $package;
    }

    public function remove(string $kind, int $id, string $kindTitle): void
    {
        unset($this->packages["{$kind}:{$id}"]);
    }

    /** @var list<array{string, string, string}> what the shop gave, by key, locale and value */
    public array $given = [];

    public function translate(TranslationPackage $package, PackageString $string, string $locale, string $value, string $kindTitle): void
    {
        $this->given[] = [$string->key(), $locale, $value];
        $this->finished[$string->key()][$locale] = $value;
    }

    public function translated(TranslationPackage $package, string $kindTitle): array
    {
        $found = [];
        foreach ($package->strings as $string) {
            if (isset($this->finished[$string->key()])) {
                $found[$string->key()] = $this->finished[$string->key()];
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> the package's words by field key
     */
    public function words(string $kind, int $id): array
    {
        $words = [];
        foreach (isset($this->packages["{$kind}:{$id}"]) ? $this->packages["{$kind}:{$id}"]->strings : [] as $string) {
            $words[$string->key()] = $string->value;
        }

        return $words;
    }
}
