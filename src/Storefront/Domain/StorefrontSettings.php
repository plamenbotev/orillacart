<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * What the storefront tells others about the shop's products (search engines, through structured data, and
 * social sites, through Open Graph tags), and what it keeps for shoppers: a wishlist and the products they viewed.
 */
final readonly class StorefrontSettings
{
    public function __construct(
        public bool $structuredData = true,
        public SharingTags $sharingTags = SharingTags::Auto,
        public WishlistPolicy $wishlist = WishlistPolicy::Guests,
        /** Product pages show the products the shopper viewed lately, kept in their browser. */
        public bool $recentlyViewed = true,
    ) {}
}
