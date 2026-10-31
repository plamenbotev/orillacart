<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application\Dto;

use OrillaCart\Storefront\Domain\StoreNotice;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateStorefrontSettings
{
    public function __construct(
        public bool $structuredData = true,
        #[Assert\Choice(choices: ['auto', 'always', 'never'])]
        public string $sharingTags = 'auto',
        #[Assert\Choice(choices: ['guests', 'accounts', 'off'])]
        public string $wishlist = 'guests',
        public bool $recentlyViewed = true,
        public bool $dataLayer = false,
        public bool $hideSingleValueFilters = true,
        public bool $listUntranslated = true,
        /** The notice over the shop's pages, wherever the Store notice block is: shown while on and with text. */
        public bool $notice = false,
        /** Plain text, in the default language. */
        #[Assert\Length(max: StoreNotice::MAX_TEXT_LENGTH)]
        public string $noticeText = '',
        /** An address starting with https:// or with / for a page of the site; empty for no link. */
        #[Assert\Length(max: StoreNotice::MAX_LINK_LENGTH)]
        public string $noticeLink = '',
        /** The link's words; empty makes the text itself the link. */
        #[Assert\Length(max: StoreNotice::MAX_LABEL_LENGTH)]
        public string $noticeLinkLabel = '',
        /** Shoppers may close the notice; their browser remembers until it changes. */
        public bool $noticeDismissible = true,
        /**
         * The notice's text and link's words in the site's other languages, by field (text, link_label) and locale;
         * null leaves them as they are.
         *
         * @var array<string, array<string, string>>|null
         */
        #[Assert\All([new Assert\All([new Assert\Type('string')])])]
        public ?array $noticeTranslations = null,
    ) {}
}
