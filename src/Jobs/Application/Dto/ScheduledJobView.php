<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * A job scheduled ahead, such as a search document to build again when a sale starts.
 */
final readonly class ScheduledJobView
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $uniqueKey,
        public array $payload,
        public string $at,
        public string $about = '',
    ) {}

    public function about(string $about): self
    {
        return new self($this->id, $this->type, $this->uniqueKey, $this->payload, $this->at, $about);
    }
}
