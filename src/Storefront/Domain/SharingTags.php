<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * Whether product pages tell social sites and chat apps how to show their links (Open Graph tags).
 */
enum SharingTags: string
{
    /** Unless an SEO plugin does it already, which would say it twice. */
    case Auto = 'auto';
    case Always = 'always';
    case Never = 'never';
}
