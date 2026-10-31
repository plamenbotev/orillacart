<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * A notice the shop puts over its pages ("Free delivery this week"), wherever the Store notice block is: its text,
 * a link to read more, and whether shoppers may close it. Shown nowhere while off or without text.
 */
final readonly class StoreNotice
{
    public const int MAX_TEXT_LENGTH = 300;
    public const int MAX_LABEL_LENGTH = 60;
    public const int MAX_LINK_LENGTH = 2000;

    public function __construct(
        public bool $shown = false,
        /** Plain text, in the default language. */
        public string $text = '',
        /** An address on the web (https://…) or on the site (/sale); empty for none. */
        public string $link = '',
        /** The link's words; empty makes the text itself the link. */
        public string $linkLabel = '',
        /** Shoppers may close it; their browser remembers until the notice changes. */
        public bool $dismissible = true,
    ) {
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH || mb_strlen($linkLabel) > self::MAX_LABEL_LENGTH || \strlen($link) > self::MAX_LINK_LENGTH) {
            throw new StorefrontRuleViolation('The store notice is too long.', 'orillacart_store_notice_length');
        }
        if ($link !== '' && preg_match('#^(https?://[^\s/]+|/(?!/))\S*$#i', $link) !== 1) {
            throw new StorefrontRuleViolation('Link the store notice to an address starting with https:// or with / for a page of the site.', 'orillacart_store_notice_link');
        }
        if ($link === '' && $linkLabel !== '') {
            throw new StorefrontRuleViolation('Give the store notice\'s link an address, or leave its words empty.', 'orillacart_store_notice_link');
        }
    }

    /** Whether shoppers see it. */
    public function visible(): bool
    {
        return $this->shown && trim($this->text) !== '';
    }
}
