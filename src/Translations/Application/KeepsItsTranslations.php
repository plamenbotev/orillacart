<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;

/**
 * A package source whose translations live in its own tables, not the translations table: email templates have
 * a template per language. The packages read and keep them through it.
 */
interface KeepsItsTranslations
{
    /**
     * @return array<string, array<string, string>> the shop's translations by PackageString::key() and locale
     */
    public function translationsOf(TranslationPackage $package): array;

    /** Keeps what the service's translators finished; false when the shop refuses it. */
    public function keep(TranslationPackage $package, PackageString $string, string $locale, string $value): bool;
}
