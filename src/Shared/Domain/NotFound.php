<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * The thing asked for does not exist. Each area extends this for its own aggregates.
 */
class NotFound extends RuleViolation
{
    /**
     * @param list<int|float|string|\Stringable> $values
     */
    public function __construct(string|Text $message, string $errorCode = 'orillacart_not_found', ?\Throwable $previous = null, array $values = [])
    {
        parent::__construct($message, $errorCode, $previous, $values);
    }
}
