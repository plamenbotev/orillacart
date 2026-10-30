<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Application\Dto;

use OrillaCart\Newsletter\Domain\Subscriber;

/** Someone's sign-up for the newsletter: since when, where, and what they agreed to. */
final readonly class SubscriptionView
{
    public function __construct(
        public string $email,
        public bool $subscribed,
        public string $subscribedAt,
        public ?string $unsubscribedAt,
        public string $source,
        public string $consent,
        /** Signed up, waiting for its owner to confirm it from the email. */
        public bool $pending = false,
        /** When its owner confirmed it from the email; null when the shop did not ask. */
        public ?string $confirmedAt = null,
    ) {}

    public static function of(Subscriber $subscriber): self
    {
        return new self($subscriber->email(), $subscriber->isSubscribed(), $subscriber->subscribedAt()->format(\DATE_ATOM), $subscriber->unsubscribedAt()?->format(\DATE_ATOM), $subscriber->source()->value, $subscriber->consent(), $subscriber->isPending(), $subscriber->confirmedAt()?->format(\DATE_ATOM));
    }
}
