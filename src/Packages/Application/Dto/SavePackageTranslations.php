<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

final readonly class SavePackageTranslations
{
    /**
     * @param array<string, array<string, list<string>|string>> $translations by word key, then locale (de_DE): the word's forms as a list, as many as the language has for a plural, or a string for a word without one; a language left out keeps its translation, an empty form removes it
     */
    public function __construct(public array $translations = []) {}
}
