<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * The thing asked for does not exist. Each area extends this for its own aggregates.
 */
class NotFound extends RuleViolation
{
    public function __construct(string $message, string $errorCode = 'orillacart_not_found', ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
