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
        /** What the receiver checks the signature with. */
        public string $secret,
        public string $createdAt,
        public ?WebhookDeliveryView $lastDelivery,
    ) {}

    public static function of(Webhook $webhook, ?WebhookDeliveryView $last): self
    {
        return new self($webhook->id(), $webhook->name(), $webhook->url(), $webhook->topics(), $webhook->isActive(), $webhook->secret(), $webhook->createdAt()->format(\DATE_ATOM), $last);
    }
}
