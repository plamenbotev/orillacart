<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * Attributes' and options' names in the site's other languages, for the views of the admin screens.
 */
final readonly class NameTranslations
{
    /**
     * @param array<int, array<string, string>> $attributes the name by locale, by attribute id
     * @param array<int, array<string, string>> $options    the name by locale, by option id
     */
    public function __construct(
        public array $attributes = [],
        public array $options = [],
    ) {}
}
