<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Application\Dto;

final readonly class NewsletterSettingsView
{
    public function __construct(
        /** Whether checkout and the account offer the newsletter. */
        public bool $offered,
        /** The words of the box customers tick. */
        public string $consent,
        /** @var list<MailingListView> the add-ons' mailing lists, and whether the sign-ups go to each */
        public array $lists,
        /** How many are subscribed now. */
        public int $subscribers,
        /** Whether a new sign-up counts only once confirmed from the email (double opt-in). */
        public bool $confirmNew = true,
    ) {}
}
