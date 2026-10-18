<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * A job to push, with its lane already chosen by its handler.
 */
final readonly class NewJob
{
    /**
     * @param array<string, mixed> $payload what the handler needs, as JSON
     */
    public function __construct(
        public string $queue,
        public string $type,
        public array $payload,
        public ?string $uniqueKey,
        public \DateTimeImmutable $availableAt,
    ) {}
}
