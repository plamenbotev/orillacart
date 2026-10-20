<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Domain;

/**
 * Whether checkout and the account offer the newsletter, in which words, and which add-ons' mailing lists get
 * the sign-ups too.
 */
final readonly class NewsletterSettings
{
    public const string DEFAULT_CONSENT = 'Email me news and offers. I can unsubscribe at any time.';

    /**
     * @param list<string> $lists the ids of the mailing lists the sign-ups go to
     *
     * @throws NewsletterRuleViolation for words too long, or none
     */
    public function __construct(
        public bool $offered = false,
        public string $consent = self::DEFAULT_CONSENT,
        public array $lists = [],
    ) {
        if (trim($consent) === '' || mb_strlen($consent) > Subscriber::MAX_CONSENT_LENGTH) {
            throw new NewsletterRuleViolation(\sprintf('Say what customers sign up for in 1 to %d characters.', Subscriber::MAX_CONSENT_LENGTH), 'orillacart_invalid_consent');
        }
    }
}
