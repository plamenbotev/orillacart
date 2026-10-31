<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * What the storefront tells others about the shop's products (search engines, through structured data, and
 * social sites, through Open Graph tags), what it keeps for shoppers (a wishlist and the products they viewed), whether its events go to the dataLayer, and which filters
 * it offers, whether it lists products not translated into the language shown, and the notice over its pages.
 */
final readonly class StorefrontSettings
{
    public function __construct(
        public bool $structuredData = true,
        public SharingTags $sharingTags = SharingTags::Auto,
        public WishlistPolicy $wishlist = WishlistPolicy::Guests,
        /** Product pages show the products the shopper viewed lately, kept in their browser. */
        public bool $recentlyViewed = true,
        /** The storefront's events also go to the dataLayer, as Google Tag Manager reads them (GA4 ecommerce). */
        public bool $dataLayer = false,
        /** The shop's filters leave out one whose products in view have a single value: it would narrow nothing. */
        public bool $hideSingleValueFilters = true,
        /** On a site in several languages, the shop lists in a language also the products not translated into it, in their own words. */
        public bool $listUntranslated = true,
        public StoreNotice $notice = new StoreNotice(),
    ) {}
}
