<?php

declare(strict_types=1);

namespace OrillaCart\Shared\View;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * An edited template that does not compile, or uses what the sandbox does not allow; the message says what and
 * on which line.
 */
final class TemplateRejected extends RuleViolation
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'orillacart_invalid_template', $previous);
    }
}
