<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * A product's words in one of the site's other languages, from its translation's post (design 51): what shoppers
 * in that language find it by and see it sorted by.
 */
final readonly class DocumentText
{
    public function __construct(
        /** WordPress's locale of the translation's language, such as de_DE. */
        public string $locale,
        public string $title,
        /** The excerpt, the content and the tags' names as plain text. */
        public string $text,
    ) {}
}
