<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application\Dto;

/**
 * An object's words, together, for a translation service (design 51): a carrier with its rates and pickup places,
 * an attribute set with its options. Translators get one package per object, as WPML's string packages are.
 */
final readonly class TranslationPackage
{
    /**
     * @param list<PackageString> $strings those with a value; an empty field has nothing to translate
     */
    public function __construct(
        /** The source's kind, such as "carrier". */
        public string $kind,
        public int $id,
        /** What translators see it as: the carrier's name. */
        public string $title,
        public array $strings,
    ) {}

    /**
     * @param list<PackageString> $strings
     */
    public static function of(string $kind, int $id, string $title, array $strings): self
    {
        return new self($kind, $id, $title, array_values(array_filter($strings, static fn(PackageString $string): bool => trim($string->value) !== '')));
    }
}
