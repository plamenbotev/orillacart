<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

/** A protocol error: an HTTP status (or an MCP error) with UCP's code and words, never a business outcome. */
final class UcpError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $ucpCode, string $content)
    {
        parent::__construct($content);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'There is no checkout session here.');
    }
}
