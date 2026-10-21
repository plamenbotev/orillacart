<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * What the storefront tells others about the shop's products: search engines, through structured data
 * (schema.org Product), and social sites, through Open Graph tags.
 */
final readonly class StorefrontSettings
{
    public function __construct(
        public bool $structuredData = true,
        public SharingTags $sharingTags = SharingTags::Auto,
    ) {}
}
