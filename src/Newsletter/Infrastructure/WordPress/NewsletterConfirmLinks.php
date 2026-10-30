<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress;

use OrillaCart\Newsletter\Application\ConfirmLinks;
use OrillaCart\Newsletter\Domain\Subscriber;
use OrillaCart\Shared\Security\Signatures;

/**
 * The link that confirms a newsletter sign-up, signed as the unsubscribe link is, for the email alone and for this
 * purpose alone: an unsubscribe link does not confirm, nor a confirm link unsubscribe.
 */
final readonly class NewsletterConfirmLinks implements ConfirmLinks
{
    public const string PARAMETER = 'orillacart_confirm_newsletter';
    public const string SIGNATURE = 'newsletter-confirm';

    public function __construct(private Signatures $signatures) {}

    public function for(string $email): string
    {
        $email = Subscriber::normalize($email);

        return add_query_arg([self::PARAMETER => rawurlencode($email), 'sig' => $this->signatures->sign(self::SIGNATURE, $email)], home_url('/'));
    }

    public function verify(string $email, string $signature): bool
    {
        return $this->signatures->verify(self::SIGNATURE, Subscriber::normalize($email), $signature);
    }
}
