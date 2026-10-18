<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * A job given up after its attempts, kept for the shop to retry or delete.
 */
final readonly class FailedJobView
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $queue,
        public string $type,
        public ?string $uniqueKey,
        public array $payload,
        public string $error,
        public int $attempts,
        public string $failedAt,
        public string $about = '',
    ) {}

    public function about(string $about): self
    {
        return new self($this->id, $this->queue, $this->type, $this->uniqueKey, $this->payload, $this->error, $this->attempts, $this->failedAt, $about);
    }
}
