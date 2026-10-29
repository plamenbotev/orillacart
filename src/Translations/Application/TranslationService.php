<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;

/**
 * A translation service the site has, such as WPML's translation management: the shop hands it each object's
 * words, and takes back what its translators finished (design 51). Without one nothing is handed.
 */
interface TranslationService
{
    /** Whether the site has it. */
    public function active(): bool;

    /** Adds the package, or brings its words up to date: words no longer in it are dropped. */
    public function register(TranslationPackage $package, string $kindTitle): void;

    public function remove(string $kind, int $id, string $kindTitle): void;

    /**
     * What its translators finished of the package's words.
     *
     * @return array<string, array<string, string>> by PackageString::key() and locale; only words translated
     */
    public function translated(TranslationPackage $package, string $kindTitle): array;

    /** Gives the service a translation the shop's own screens made, as finished, for its translators to see. */
    public function translate(TranslationPackage $package, PackageString $string, string $locale, string $value, string $kindTitle): void;
}
