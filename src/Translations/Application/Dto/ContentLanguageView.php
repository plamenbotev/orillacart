<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application\Dto;

/** A language the shop's words can be translated into, for the admin screens. */
final readonly class ContentLanguageView
{
    public function __construct(
        public string $code,
        public string $locale,
        public string $name,
        /** The site's default language: the fields' own values are in it. */
        public bool $default,
    ) {}
}
