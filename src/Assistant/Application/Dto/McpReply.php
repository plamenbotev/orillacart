<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application\Dto;

/**
 * The HTTP answer to one MCP message: a status and a JSON-RPC response, or no body for a notification.
 */
final readonly class McpReply
{
    /**
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        public int $status,
        public ?array $body,
    ) {}
}
