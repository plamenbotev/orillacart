<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application\Dto;

use OrillaCart\Storefront\Domain\StorefrontSettings;

final readonly class StorefrontSettingsView
{
    public function __construct(
        /** Product pages carry schema.org Product data for search engines. */
        public bool $structuredData,
        /** auto (unless an SEO plugin does), always or never: Open Graph tags for social sites. */
        public string $sharingTags,
    ) {}

    public static function of(StorefrontSettings $settings): self
    {
        return new self($settings->structuredData, $settings->sharingTags->value);
    }
}
