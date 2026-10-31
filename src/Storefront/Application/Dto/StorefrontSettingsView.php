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
        /** guests, accounts or off */
        public string $wishlist,
        public bool $recentlyViewed,
        public bool $dataLayer,
        /** The shop's filters leave out one whose products in view have a single value. */
        public bool $hideSingleValueFilters,
        /** On a site in several languages, products not translated into the language shown are listed in their own words. */
        public bool $listUntranslated,
        /** The notice over the shop's pages is on; shown while it has text. */
        public bool $notice = false,
        public string $noticeText = '',
        public string $noticeLink = '',
        public string $noticeLinkLabel = '',
        public bool $noticeDismissible = true,
        /** @var array<string, array<string, string>> the notice's text and link's words by field and locale */
        public array $noticeTranslations = [],
    ) {}

    /**
     * @param array<string, array<string, string>> $noticeTranslations by field and locale
     */
    public static function of(StorefrontSettings $settings, array $noticeTranslations = []): self
    {
        $notice = $settings->notice;

        return new self($settings->structuredData, $settings->sharingTags->value, $settings->wishlist->value, $settings->recentlyViewed, $settings->dataLayer, $settings->hideSingleValueFilters, $settings->listUntranslated, $notice->shown, $notice->text, $notice->link, $notice->linkLabel, $notice->dismissible, $noticeTranslations);
    }
}
