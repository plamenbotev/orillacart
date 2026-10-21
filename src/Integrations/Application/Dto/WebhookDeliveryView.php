<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

use OrillaCart\Integrations\Domain\WebhookDelivery;

final readonly class WebhookDeliveryView
{
    public function __construct(
        public string $event,
        public string $topic,
        public int $attempt,
        public ?int $status,
        public bool $succeeded,
        public string $error,
        public int $milliseconds,
        public string $attemptedAt,
    ) {}

    public static function of(WebhookDelivery $delivery): self
    {
        return new self($delivery->event(), $delivery->topic(), $delivery->attempt(), $delivery->status(), $delivery->succeeded(), $delivery->error(), $delivery->milliseconds(), $delivery->attemptedAt()->format(\DATE_ATOM));
    }
}
