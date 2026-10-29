<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * An email template to edit, for every language or for one: what the shop uses now, the template for every language
 * a language's falls back to, and the theme's or the plugin's file they started from.
 */
final readonly class EmailTemplateSourceView
{
    public function __construct(
        public string $name,
        public string $source,
        public ?string $fileSource,
        public bool $edited,
        public string $previews,
        public ?string $updatedAt = null,
        /** The site's language it is for (de_DE); empty for every language. */
        public string $locale = '',
        /** For a language: the shop's template for every language, which its readers get while it has none of its own. */
        public ?string $everyLanguage = null,
    ) {}
}
