<?php

declare(strict_types=1);

namespace OrillaCart\Shared\View;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;

/**
 * An edited template that does not compile, or uses what the sandbox does not allow; the message says what and
 * on which line.
 */
final class TemplateRejected extends RuleViolation
{
    /**
     * @param list<int|float|string|\Stringable> $values
     */
    public function __construct(string|Text $message, ?\Throwable $previous = null, array $values = [])
    {
        parent::__construct($message, 'orillacart_invalid_template', $previous, $values);
    }
}
