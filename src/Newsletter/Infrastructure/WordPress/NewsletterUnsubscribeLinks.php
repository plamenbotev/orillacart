<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress;

use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Domain\Subscriber;
use OrillaCart\Notifications\Application\UnsubscribeLinks;
use OrillaCart\Shared\Security\Signatures;

/**
 * A link that unsubscribes the email from the newsletter, signed so no one unsubscribes someone else, with no
 * account and no expiry: a guest who signed up at checkout leaves from any of the shop's emails.
 */
final readonly class NewsletterUnsubscribeLinks implements UnsubscribeLinks
{
    public const string PARAMETER = 'orillacart_unsubscribe';
    public const string SIGNATURE = 'newsletter-unsubscribe';

    public function __construct(
        private Newsletter $newsletter,
        private Signatures $signatures,
    ) {}

    public function for(string $email): ?string
    {
        if (!$this->newsletter->isSubscribed($email)) {
            return null;
        }
        $email = Subscriber::normalize($email);

        return add_query_arg([self::PARAMETER => rawurlencode($email), 'sig' => $this->signatures->sign(self::SIGNATURE, $email)], home_url('/'));
    }

    public function verify(string $email, string $signature): bool
    {
        return $this->signatures->verify(self::SIGNATURE, Subscriber::normalize($email), $signature);
    }
}
