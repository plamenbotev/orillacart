<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * A job a worker claimed, at the version it claimed.
 */
final readonly class QueuedJob
{
    /**
     * @param array<string, mixed> $payload
     * @param int                  $attempts this one included
     */
    public function __construct(
        public string $id,
        public string $queue,
        public string $type,
        public ?string $uniqueKey,
        public array $payload,
        public int $version,
        public int $attempts,
    ) {}
}
