<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

final readonly class WebhookResponse
{
    /**
     * @param int|null $status what the address answered; null when it could not be reached
     * @param string   $error  why not, or the start of what it answered when that was not a success
     */
    public function __construct(
        public ?int $status,
        public string $error,
        public int $milliseconds,
    ) {}

    public function succeeded(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
