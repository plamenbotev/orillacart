<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

/**
 * The state a tool's question to the owner carries to the client and back (MCP's requestState): signed, so the
 * client cannot change it, short-lived, and good once, so a yes cannot be replayed.
 */
interface RequestStates
{
    /**
     * @param array<string, mixed> $payload
     */
    public function issue(array $payload, int $seconds): string;

    /**
     * The payload, once: null when the state was changed, has run out, or was used before.
     *
     * @return array<string, mixed>|null
     */
    public function redeem(string $state): ?array;
}
