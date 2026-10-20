<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * An email template to edit: what the shop uses now, and the theme's or the plugin's file it started from.
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
    ) {}
}
