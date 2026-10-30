<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Domain;

/**
 * Whether checkout and the account offer the newsletter, in which words, which add-ons' mailing lists get the
 * sign-ups too, and whether a sign-up waits for its owner to confirm it from their email.
 */
final readonly class NewsletterSettings
{
    public const string DEFAULT_CONSENT = 'Email me news and offers. I can unsubscribe at any time.';

    /**
     * @param list<string> $lists      the ids of the mailing lists the sign-ups go to
     * @param bool         $confirmNew whether a new sign-up counts only once its owner followed the link emailed to
     *                                 them (double opt-in), so nobody signs up an address that is not theirs
     *
     * @throws NewsletterRuleViolation for words too long, or none
     */
    public function __construct(
        public bool $offered = false,
        public string $consent = self::DEFAULT_CONSENT,
        public array $lists = [],
        public bool $confirmNew = true,
    ) {
        if (trim($consent) === '' || mb_strlen($consent) > Subscriber::MAX_CONSENT_LENGTH) {
            throw new NewsletterRuleViolation('Say what customers sign up for in 1 to %d characters.', 'orillacart_invalid_consent', values: [Subscriber::MAX_CONSENT_LENGTH]);
        }
    }
}
