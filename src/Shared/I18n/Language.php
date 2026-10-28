<?php

declare(strict_types=1);

namespace OrillaCart\Shared\I18n;

/**
 * A language the site's content is written in, as Polylang or WPML names it.
 */
final readonly class Language
{
    public function __construct(
        /** The plugin's code for it, such as "de"; what translations and URLs go by. */
        public string $code,
        /** WordPress's locale, such as de_DE: what the shop's own words are translated by. */
        public string $locale,
        public string $name,
    ) {}
}
