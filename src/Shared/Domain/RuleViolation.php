<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * A business rule refused the change. Each area extends this for its own rules;
 * the message is written for the person who asked for the change.
 */
class RuleViolation extends \DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'orillacart_rule_violation',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
