<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

use OrillaCart\Integrations\Domain\Webhook;

final readonly class WebhookView
{
    /**
     * @param list<string> $topics
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public array $topics,
        public bool $active,
        /** What the receiver checks the signature with: shown once, when the webhook or a new secret is made. */
        #[\SensitiveParameter]
        public ?string $secret,
        public string $createdAt,
        public ?WebhookDeliveryView $lastDelivery,
        /** The shop manager who answers for it: who made it, or saved it last; null for none. */
        public ?int $ownerId = null,
        /** Their name; empty for an account that is gone. */
        public string $owner = '',
        public int $failureLimit = Webhook::DEFAULT_FAILURE_LIMIT,
        public int $failedInARow = 0,
        /** Why the shop switched it off: "owner_left" or "failing"; null when it is on, or a manager switched it off. */
        public ?string $offReason = null,
        public ?string $offAt = null,
    ) {}

    public static function of(Webhook $webhook, ?WebhookDeliveryView $last, bool $withSecret = false, string $owner = ''): self
    {
        return new self(
            $webhook->id(),
            $webhook->name(),
            $webhook->url(),
            $webhook->topics(),
            $webhook->isActive(),
            $withSecret ? $webhook->secret() : null,
            $webhook->createdAt()->format(\DATE_ATOM),
            $last,
            $webhook->ownerId(),
            $owner,
            $webhook->failureLimit(),
            $webhook->failedInARow(),
            $webhook->offReason(),
            $webhook->offAt()?->format(\DATE_ATOM),
        );
    }
}
