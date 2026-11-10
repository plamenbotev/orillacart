<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress;

use OrillaCart\Newsletter\Application\ConfirmLinks;
use OrillaCart\Newsletter\Domain\Subscriber;
use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;

/**
 * The link that confirms a newsletter sign-up, signed as the unsubscribe link is, for the email and the day it was
 * made, and for this purpose alone: an unsubscribe link does not confirm, nor a confirm link unsubscribe. It works
 * for VALID_DAYS; after that, signing up again sends a new one.
 */
final readonly class NewsletterConfirmLinks implements ConfirmLinks
{
    public const string PARAMETER = 'orillacart_confirm_newsletter';
    public const string DAY_PARAMETER = 'day';
    public const string SIGNATURE = 'newsletter-confirm';
    public const int VALID_DAYS = 7;

    public function __construct(
        private Signatures $signatures,
        private ClockInterface $clock,
    ) {}

    public function for(string $email): string
    {
        $email = Subscriber::normalize($email);
        $day = $this->today();

        return add_query_arg([self::PARAMETER => rawurlencode($email), self::DAY_PARAMETER => $day, 'sig' => $this->signatures->sign(self::SIGNATURE, "{$email}:{$day}")], home_url('/'));
    }

    /** Whether the shop made the link for the email on that day; whether it still works is expired()'s to tell. */
    public function verify(string $email, string $day, string $signature): bool
    {
        return preg_match('/^\d{1,6}$/', $day) === 1 && $this->signatures->verify(self::SIGNATURE, Subscriber::normalize($email) . ':' . $day, $signature);
    }

    /** Whether a link made on the day is too old to confirm, or made on a day still to come. */
    public function expired(string $day): bool
    {
        $age = $this->today() - (int) $day;

        return $age < 0 || $age >= self::VALID_DAYS;
    }

    private function today(): int
    {
        return intdiv($this->clock->now()->getTimestamp(), 86400);
    }
}
