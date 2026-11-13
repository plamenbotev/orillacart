<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

/** What a change answered, by its idempotency key, for a day: a retry gets the same answer. */
interface IdempotentAnswers
{
    /** @return array{body: string, answer: array{status: int, body: array<string, mixed>}}|null */
    public function get(string $key): ?array;

    /** @param array{status: int, body: array<string, mixed>} $answer */
    public function keep(string $key, string $body, array $answer): void;
}
