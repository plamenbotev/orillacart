<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Newsletter\Infrastructure\WordPress\NewsletterUnsubscribeLinks;
use OrillaCart\Notifications\Application\UnsubscribeLinks;

/**
 * The shop's emails carry the newsletter's unsubscribe link, for a subscriber.
 */
final readonly class NewsletterUnsubscribeUrls implements UnsubscribeLinks
{
    public function __construct(private NewsletterUnsubscribeLinks $links) {}

    public function for(string $email): ?string
    {
        return $this->links->for($email);
    }
}
